"""Common Crawl import pipeline: S3 parquet -> local DuckDB -> MySQL index."""

from __future__ import annotations

import json
import logging
import os
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from urllib.parse import urlsplit

from lead_crawler.services.domain_normalizer import DomainNormalizer

try:
    import boto3  # type: ignore
except ImportError:  # pragma: no cover
    boto3 = None  # type: ignore

logger = logging.getLogger(__name__)

_CC_S3_BUCKET = "commoncrawl"
_CC_S3_PREFIX_TEMPLATE = "cc-index/table/cc-main/warc/crawl={crawl_id}/subset=warc/"
_VALID_FETCH_STATUSES = (200, 301, 302)
_DEFAULT_CRAWLS = ["CC-MAIN-2025-13"]
_DEFAULT_MINIMUM_IMPORT_SCORE = 0.05


@dataclass(slots=True)
class FileProcessingStats:
    rows_read_before_filters: int = 0
    rows_skipped_invalid_domain: int = 0
    rows_skipped_country_filter: int = 0
    rows_accepted_after_country_filter: int = 0
    domains_extracted: int = 0
    domains_upserted: int = 0
    raw_row_samples_logged: int = 0
    accepted_domain_samples_logged: int = 0


@dataclass(slots=True)
class CommonCrawlImporterConfig:
    cache_dir: str = os.getenv("COMMON_CRAWL_CACHE_DIR", "/tmp/common-crawl-cache")
    region_name: str = os.getenv("AWS_DEFAULT_REGION", "us-east-1")
    mysql_host: str = os.getenv("MYSQL_HOST", "mysql")
    mysql_port: int = int(os.getenv("MYSQL_PORT", "3306"))
    mysql_database: str = os.getenv("MYSQL_DATABASE", "commerce_leads")
    mysql_user: str = os.getenv("MYSQL_USER", "app")
    mysql_password: str = os.getenv("MYSQL_PASSWORD", "app")


class CommonCrawlImporter:
    """Import Common Crawl parquet rows into the local MySQL domain index."""

    def __init__(self, config: CommonCrawlImporterConfig | None = None) -> None:
        self.config = config or CommonCrawlImporterConfig()
        self._normalizer = DomainNormalizer()

    @staticmethod
    def pattern_match_score(url: str) -> tuple[float, list[str]]:
        url_lower = (url or "").lower()
        scoring_rules = (
            ("product", 0.30, ("/product", "/products")),
            ("shop", 0.25, ("/shop",)),
            ("cart", 0.20, ("/cart",)),
            ("checkout", 0.20, ("/checkout",)),
            ("warenkorb", 0.20, ("/warenkorb",)),
            ("category", 0.15, ("/category", "/kategorie")),
            ("collections", 0.15, ("/collections",)),
            ("store", 0.15, ("/store",)),
        )

        score = 0.0
        matched: list[str] = []
        for label, value, needles in scoring_rules:
            if any(needle in url_lower for needle in needles):
                score += value
                matched.append(label)

        return min(1.0, score), matched

    def _get_s3_client(self):
        if boto3 is None:
            raise RuntimeError("boto3 is required for Common Crawl import jobs (pip install boto3).")
        return boto3.client("s3", region_name=self.config.region_name)

    def _list_parquet_keys(self, s3_client, crawl_id: str, max_files: int) -> list[str]:
        prefix = _CC_S3_PREFIX_TEMPLATE.format(crawl_id=crawl_id)
        logger.info("Listing S3 parquet keys: bucket=%s prefix=%s max_files=%s", _CC_S3_BUCKET, prefix, max_files)
        response = s3_client.list_objects_v2(Bucket=_CC_S3_BUCKET, Prefix=prefix, MaxKeys=max(max_files * 5, 100))
        all_keys = [obj["Key"] for obj in response.get("Contents", []) if str(obj.get("Key", "")).endswith(".parquet")]
        selected = all_keys[:max_files]
        logger.info("S3 keys listed for crawl %s: %d", crawl_id, len(all_keys))
        for index, key in enumerate(selected, start=1):
            logger.info("S3 parquet key %s/%s for %s: %s", index, len(selected), crawl_id, key)
        return selected

    def _download_parquet_file(self, s3_client, crawl_id: str, key: str) -> str:
        filename = key.rsplit("/", 1)[-1]
        crawl_cache_dir = os.path.join(self.config.cache_dir, crawl_id)
        os.makedirs(crawl_cache_dir, exist_ok=True)
        local_path = os.path.join(crawl_cache_dir, filename)
        logger.info("Download start: s3://%s/%s -> %s", _CC_S3_BUCKET, key, local_path)
        s3_client.download_file(_CC_S3_BUCKET, key, local_path)
        logger.info("Download completed: %s", local_path)
        return local_path

    def _extract_rows_from_parquet(self, local_path: str, crawl_id: str) -> list[tuple]:
        try:
            import duckdb  # type: ignore
        except ModuleNotFoundError as exc:
            raise RuntimeError("duckdb is required for Common Crawl import jobs (pip install duckdb).") from exc

        sql = f"""
SELECT
    coalesce(url, '') AS url,
    lower(coalesce(url_host_tld, '')) AS tld,
    lower(coalesce(url_host_name, '')) AS host,
    '{crawl_id}' AS crawl,
    'warc' AS subset
FROM read_parquet('{local_path}', union_by_name=true)
WHERE fetch_status IN ({", ".join(str(s) for s in _VALID_FETCH_STATUSES)})
  AND lower(coalesce(content_mime_detected, '')) LIKE '%text/html%'
""".strip()
        logger.info("DuckDB SQL for local file %s:\n%s", local_path, sql)

        with duckdb.connect(database=":memory:") as conn:
            rows = conn.execute(sql).fetchall()

        logger.info("DuckDB rows returned for %s: %d", local_path, len(rows))
        return rows

    def _aggregate_rows(
        self,
        rows: list[tuple],
        fallback_crawl_id: str,
        countries: list[str],
        minimum_import_score: float,
    ) -> tuple[dict[str, dict], FileProcessingStats]:
        now = datetime.now(timezone.utc).replace(tzinfo=None)
        aggregated: dict[str, dict] = {}
        stats = FileProcessingStats(rows_read_before_filters=len(rows))
        countries_normalized = [str(country).strip().lower() for country in countries if str(country).strip()]

        for index, row in enumerate(rows, start=1):
            url, tld, host, crawl, _subset = row
            if stats.raw_row_samples_logged < 10:
                logger.info("Sample CC row %s: url=%s parquet_tld=%s host=%s", index, url, tld, host)
                stats.raw_row_samples_logged += 1

            domain = self._normalizer.normalize(host or urlsplit(url).netloc)
            if not domain:
                stats.rows_skipped_invalid_domain += 1
                continue

            domain_tld = domain.rsplit(".", 1)[-1].lower()
            if countries_normalized and domain_tld not in countries_normalized:
                stats.rows_skipped_country_filter += 1
                continue

            stats.rows_accepted_after_country_filter += 1
            score, matched_patterns = self.pattern_match_score(url)
            if score <= 0:
                score = minimum_import_score

            if stats.accepted_domain_samples_logged < 10:
                logger.info(
                    "Accepted domain sample %s: domain=%s domain_tld=%s score=%.3f source_url=%s",
                    stats.accepted_domain_samples_logged + 1,
                    domain,
                    domain_tld,
                    score,
                    url,
                )
                stats.accepted_domain_samples_logged += 1

            entry = aggregated.get(domain)
            if entry is None:
                aggregated[domain] = {
                    "domain": domain,
                    "tld": domain_tld,
                    "ecommerce_score": score,
                    "matched_patterns": set(matched_patterns),
                    "source_url": url,
                    "crawl_id": crawl or fallback_crawl_id,
                    "last_seen_at": now,
                }
                continue

            entry["ecommerce_score"] = min(1.0, float(entry["ecommerce_score"]) + float(score))
            entry["matched_patterns"].update(matched_patterns)
            if not entry.get("source_url") and url:
                entry["source_url"] = url
            entry["crawl_id"] = crawl or fallback_crawl_id
            entry["last_seen_at"] = now

        stats.domains_extracted = len(aggregated)
        return aggregated, stats

    def _mysql_connection(self):
        try:
            import MySQLdb  # type: ignore
        except ModuleNotFoundError as exc:  # pragma: no cover
            raise RuntimeError("mysqlclient is required for Common Crawl import jobs.") from exc

        return MySQLdb.connect(
            host=self.config.mysql_host,
            port=self.config.mysql_port,
            user=self.config.mysql_user,
            passwd=self.config.mysql_password,
            db=self.config.mysql_database,
            charset="utf8mb4",
            use_unicode=True,
            autocommit=False,
        )

    def _fetch_existing_patterns(self, conn, domains: list[str]) -> dict[str, set[str]]:
        if not domains:
            return {}
        placeholders = ", ".join(["%s"] * len(domains))
        sql = (
            "SELECT domain, matched_patterns FROM common_crawl_domains "
            f"WHERE domain IN ({placeholders})"
        )
        with conn.cursor() as cursor:
            cursor.execute(sql, domains)
            rows = cursor.fetchall()

        existing: dict[str, set[str]] = {}
        for domain, matched_patterns in rows:
            try:
                parsed = json.loads(matched_patterns) if matched_patterns else []
            except (TypeError, json.JSONDecodeError):
                parsed = []
            existing[str(domain)] = {str(item) for item in parsed if item}
        return existing

    def _upsert_domains(self, domains_map: dict[str, dict], batch_size: int) -> int:
        if not domains_map:
            return 0

        all_domains = list(domains_map.keys())
        upserted = 0

        with self._mysql_connection() as conn:
            for offset in range(0, len(all_domains), batch_size):
                batch_domains = all_domains[offset: offset + batch_size]
                existing = self._fetch_existing_patterns(conn, batch_domains)

                values = []
                for domain in batch_domains:
                    item = domains_map[domain]
                    merged_patterns = set(item["matched_patterns"]) | existing.get(domain, set())
                    patterns_json = json.dumps(sorted(merged_patterns))
                    values.append(
                        (
                            item["domain"],
                            item["tld"],
                            float(item["ecommerce_score"]),
                            patterns_json,
                            item.get("source_url"),
                            item.get("crawl_id"),
                            item["last_seen_at"].strftime("%Y-%m-%d %H:%M:%S"),
                        )
                    )

                sql = """
INSERT INTO common_crawl_domains
    (domain, tld, ecommerce_score, matched_patterns, source_url, crawl_id, last_seen_at, created_at, updated_at)
VALUES
    (%s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    tld = VALUES(tld),
    ecommerce_score = GREATEST(ecommerce_score, VALUES(ecommerce_score)),
    matched_patterns = VALUES(matched_patterns),
    source_url = COALESCE(source_url, VALUES(source_url)),
    crawl_id = VALUES(crawl_id),
    last_seen_at = VALUES(last_seen_at),
    updated_at = NOW()
""".strip()

                with conn.cursor() as cursor:
                    cursor.executemany(sql, values)
                conn.commit()
                upserted += len(values)
                logger.info("Upsert batch completed: size=%d total_upserted=%d", len(values), upserted)

        return upserted

    def run_import(self, payload: dict) -> dict:
        started_at = time.perf_counter()
        crawls = payload.get("cc_crawls") or _DEFAULT_CRAWLS
        files_per_crawl = int(payload.get("cc_files_per_crawl", 10))
        countries = [str(country).lower() for country in (payload.get("countries") or []) if str(country).strip()]
        batch_size = max(1, int(payload.get("batch_size", 1000)))
        delete_after_process = bool(payload.get("delete_after_process", True))
        minimum_import_score = float(payload.get("minimum_import_score", _DEFAULT_MINIMUM_IMPORT_SCORE))

        logger.info(
            "Starting Common Crawl import: crawls=%s countries=%s files_per_crawl=%s batch_size=%s delete_after_process=%s minimum_import_score=%s cache_dir=%s",
            crawls,
            countries,
            files_per_crawl,
            batch_size,
            delete_after_process,
            minimum_import_score,
            self.config.cache_dir,
        )

        s3_client = self._get_s3_client()

        files_listed = 0
        files_downloaded = 0
        files_processed = 0
        files_skipped = 0
        total_domains_extracted = 0
        domains_upserted = 0
        rows_read_before_filters = 0
        rows_skipped_invalid_domain = 0
        rows_skipped_country_filter = 0
        rows_accepted_after_country_filter = 0
        listed_keys: list[str] = []
        downloaded_keys: list[str] = []
        processed_keys: list[str] = []
        skipped_keys: list[str] = []

        for crawl_id in crawls:
            keys = self._list_parquet_keys(s3_client, crawl_id, files_per_crawl)
            files_listed += len(keys)
            listed_keys.extend(keys)

            for key in keys:
                local_path = None
                try:
                    local_path = self._download_parquet_file(s3_client, crawl_id, key)
                    files_downloaded += 1
                    downloaded_keys.append(key)

                    rows = self._extract_rows_from_parquet(local_path, crawl_id=crawl_id)
                    domains_map, file_stats = self._aggregate_rows(
                        rows,
                        fallback_crawl_id=crawl_id,
                        countries=countries,
                        minimum_import_score=minimum_import_score,
                    )
                    rows_read_before_filters += file_stats.rows_read_before_filters
                    rows_skipped_invalid_domain += file_stats.rows_skipped_invalid_domain
                    rows_skipped_country_filter += file_stats.rows_skipped_country_filter
                    rows_accepted_after_country_filter += file_stats.rows_accepted_after_country_filter
                    file_domains = file_stats.domains_extracted
                    total_domains_extracted += file_domains
                    logger.info("DuckDB rows returned: %d", file_stats.rows_read_before_filters)
                    logger.info("Rows skipped invalid domain: %d", file_stats.rows_skipped_invalid_domain)
                    logger.info("Rows skipped by country filter: %d", file_stats.rows_skipped_country_filter)
                    logger.info("Rows accepted after country filter: %d", file_stats.rows_accepted_after_country_filter)
                    logger.info("Domains extracted: %d", file_domains)

                    if domains_map:
                        file_upserted = self._upsert_domains(domains_map, batch_size=batch_size)
                        domains_upserted += file_upserted
                        file_stats.domains_upserted = file_upserted
                    logger.info("Domains upserted: %d", file_stats.domains_upserted)
                    files_processed += 1
                    processed_keys.append(key)
                except Exception as exc:  # noqa: BLE001
                    files_skipped += 1
                    skipped_keys.append(key)
                    logger.warning("Skipped parquet file: key=%s reason=%s", key, exc)
                finally:
                    if delete_after_process and local_path and os.path.exists(local_path):
                        try:
                            os.remove(local_path)
                        except OSError as exc:  # pragma: no cover
                            logger.warning("Failed to delete parquet cache file %s: %s", local_path, exc)

        duration_seconds = round(time.perf_counter() - started_at, 3)
        summary = {
            "job_type": "common_crawl_import",
            "backend": "duckdb_import",
            "crawls_processed": len(crawls),
            "crawls": crawls,
            "countries": countries,
            "parquet_files_listed": files_listed,
            "parquet_keys_listed": listed_keys,
            "parquet_files_downloaded": files_downloaded,
            "parquet_keys_downloaded": downloaded_keys,
            "parquet_files_processed": files_processed,
            "parquet_keys_processed": processed_keys,
            "parquet_files_skipped": files_skipped,
            "parquet_keys_skipped": skipped_keys,
            "domains_extracted": total_domains_extracted,
            "domains_upserted": domains_upserted,
            "rows_read_before_filters": rows_read_before_filters,
            "rows_skipped_invalid_domain": rows_skipped_invalid_domain,
            "rows_skipped_country_filter": rows_skipped_country_filter,
            "rows_accepted_after_country_filter": rows_accepted_after_country_filter,
            "minimum_import_score": minimum_import_score,
            "duration_seconds": duration_seconds,
        }
        logger.info("Common Crawl import summary: %s", summary)

        if files_processed == 0:
            raise RuntimeError("Common Crawl import failed: all parquet files were skipped.")

        return summary
