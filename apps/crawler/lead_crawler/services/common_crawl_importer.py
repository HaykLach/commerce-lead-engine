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
_DEFAULT_FETCH_CHUNK_SIZE = int(os.getenv("COMMON_CRAWL_FETCH_CHUNK_SIZE", "10000"))

COUNTRY_SIGNALS: dict[str, dict[str, list[str]]] = {
    "de": {
        "tlds": ["de"],
        "url_patterns": [
            "/de/", "/de-de/", "/de_de/",
            "/deutschland",
            "/produkte", "/produkt",
            "/warenkorb", "/kategorie",
        ],
    },
    "nl": {
        "tlds": ["nl"],
        "url_patterns": [
            "/nl/", "/nl-nl/",
            "/nederland",
            "/producten", "/product",
            "/winkelwagen", "/categorie",
        ],
    },
    "fr": {
        "tlds": ["fr"],
        "url_patterns": [
            "/fr/", "/fr-fr/",
            "/france",
            "/produits", "/produit",
            "/panier", "/categorie",
        ],
    },
    "it": {
        "tlds": ["it"],
        "url_patterns": [
            "/it/", "/it-it/",
            "/italia",
            "/prodotti", "/prodotto",
            "/carrello", "/categoria",
        ],
    },
    "es": {
        "tlds": ["es"],
        "url_patterns": [
            "/es/", "/es-es/",
            "/espana", "/españa",
            "/productos", "/producto",
            "/carrito", "/categoria",
        ],
    },
    "ch": {
        "tlds": ["ch"],
        "url_patterns": [
            "/ch/", "/de-ch/", "/fr-ch/", "/it-ch/",
            "/schweiz", "/suisse", "/svizzera",
            "/produkte", "/produits", "/prodotti",
            "/warenkorb", "/panier", "/carrello",
        ],
    },
    "us": {
        "tlds": ["us"],
        "url_patterns": [
            "/us/", "/en-us/", "/en_us/",
            "/usa", "/united-states",
            "/products", "/product",
            "/cart", "/checkout", "/category",
        ],
    },
}


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
    fetch_chunk_size: int = max(1, _DEFAULT_FETCH_CHUNK_SIZE)


class CommonCrawlImporter:
    """Import Common Crawl parquet rows into the local MySQL domain index."""

    def __init__(self, config: CommonCrawlImporterConfig | None = None) -> None:
        self.config = config or CommonCrawlImporterConfig()
        self._normalizer = DomainNormalizer()

    @staticmethod
    def detect_country_signals(url: str, domain: str, country: str) -> tuple[bool, list[str]]:
        url_l = (url or "").lower()
        domain_l = (domain or "").lower()

        config = COUNTRY_SIGNALS.get(country)
        if not config:
            return False, []

        tld = domain_l.rsplit(".", 1)[-1] if "." in domain_l else ""

        signals: list[str] = []
        if tld in config["tlds"]:
            signals.append(f"tld:{country}")

        for pattern in config["url_patterns"]:
            if pattern in url_l:
                signals.append(f"url:{pattern}")

        return bool(signals), signals

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

    def _duckdb_sql(self, local_path: str) -> str:
        return f"""
SELECT
    coalesce(url, '') AS url
FROM read_parquet('{local_path}', union_by_name=true)
WHERE fetch_status IN ({", ".join(str(s) for s in _VALID_FETCH_STATUSES)})
  AND lower(coalesce(content_mime_detected, '')) LIKE '%text/html%'
""".strip()

    def _process_parquet_file(
        self,
        local_path: str,
        crawl_id: str,
        country: str,
        batch_size: int,
        minimum_import_score: float,
        fetch_chunk_size: int,
    ) -> FileProcessingStats:
        try:
            import duckdb  # type: ignore
        except ModuleNotFoundError as exc:
            raise RuntimeError("duckdb is required for Common Crawl import jobs (pip install duckdb).") from exc

        sql = self._duckdb_sql(local_path)
        logger.info("DuckDB SQL for local file %s:\n%s", local_path, sql)
        file_started = time.perf_counter()
        now = datetime.now(timezone.utc).replace(tzinfo=None)
        stats = FileProcessingStats()
        chunk_number = 0

        with duckdb.connect(database=":memory:") as conn:
            cursor = conn.execute(sql)
            while True:
                rows = cursor.fetchmany(fetch_chunk_size)
                if not rows:
                    break

                chunk_number += 1
                chunk_invalid_domain = 0
                chunk_skipped_country = 0
                chunk_accepted_country = 0
                chunk_aggregated: dict[str, dict] = {}
                stats.rows_read_before_filters += len(rows)

                for row in rows:
                    url = row[0]
                    if not url:
                        chunk_invalid_domain += 1
                        continue

                    domain = self._normalizer.normalize(urlsplit(url).netloc)
                    if not domain:
                        chunk_invalid_domain += 1
                        continue

                    matched_country, country_signals = self.detect_country_signals(url=url, domain=domain, country=country)
                    if not matched_country:
                        chunk_skipped_country += 1
                        continue
                    chunk_accepted_country += 1

                    score, matched_patterns = self.pattern_match_score(url)
                    if score <= 0:
                        score = minimum_import_score

                    domain_tld = domain.rsplit(".", 1)[-1].lower()
                    entry = chunk_aggregated.get(domain)
                    if entry is None:
                        chunk_aggregated[domain] = {
                            "domain": domain,
                            "tld": domain_tld,
                            "country": country,
                            "country_signals": set(country_signals),
                            "ecommerce_score": score,
                            "matched_patterns": set(matched_patterns),
                            "source_url": url,
                            "crawl_id": crawl_id,
                            "last_seen_at": now,
                        }
                        continue

                    entry["ecommerce_score"] = min(1.0, float(entry["ecommerce_score"]) + float(score))
                    entry["country_signals"].update(country_signals)
                    entry["matched_patterns"].update(matched_patterns)
                    if not entry.get("source_url") and url:
                        entry["source_url"] = url
                    entry["crawl_id"] = crawl_id
                    entry["last_seen_at"] = now

                domains_extracted = len(chunk_aggregated)
                domains_upserted = self._upsert_domains(chunk_aggregated, batch_size=batch_size)

                logger.info(
                    "Chunk processed: file=%s chunk_number=%d rows_read=%d rows_skipped_invalid_domain=%d "
                    "rows_skipped_country_signal=%d rows_accepted_after_country_signal=%d domains_extracted=%d domains_upserted=%d",
                    local_path,
                    chunk_number,
                    len(rows),
                    chunk_invalid_domain,
                    chunk_skipped_country,
                    chunk_accepted_country,
                    domains_extracted,
                    domains_upserted,
                )

                stats.rows_skipped_invalid_domain += chunk_invalid_domain
                stats.rows_skipped_country_filter += chunk_skipped_country
                stats.rows_accepted_after_country_filter += chunk_accepted_country
                stats.domains_extracted += domains_extracted
                stats.domains_upserted += domains_upserted

        logger.info(
            "Parquet file completed: file=%s total_rows_read=%d total_invalid_domains=%d total_skipped_by_country=%d "
            "total_accepted_by_country=%d total_domains_upserted=%d duration_seconds=%.3f",
            local_path,
            stats.rows_read_before_filters,
            stats.rows_skipped_invalid_domain,
            stats.rows_skipped_country_filter,
            stats.rows_accepted_after_country_filter,
            stats.domains_upserted,
            time.perf_counter() - file_started,
        )
        return stats

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
                            item.get("country"),
                            float(item["ecommerce_score"]),
                            patterns_json,
                            json.dumps(sorted(item.get("country_signals", set()))),
                            item.get("source_url"),
                            item.get("crawl_id"),
                            item["last_seen_at"].strftime("%Y-%m-%d %H:%M:%S"),
                        )
                    )

                sql = """
INSERT INTO common_crawl_domains
    (domain, tld, country, ecommerce_score, matched_patterns, country_signals, source_url, crawl_id, last_seen_at, created_at, updated_at)
VALUES
    (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    tld = VALUES(tld),
    country = VALUES(country),
    ecommerce_score = GREATEST(ecommerce_score, VALUES(ecommerce_score)),
    matched_patterns = VALUES(matched_patterns),
    country_signals = VALUES(country_signals),
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
        countries = [str(item).strip().lower() for item in (payload.get("countries") or []) if str(item).strip()]
        if len(countries) != 1:
            raise ValueError("Exactly one country must be provided for common_crawl_import jobs")
        country = countries[0]
        if country not in COUNTRY_SIGNALS:
            raise ValueError(f"Unsupported country: {country}")
        batch_size = max(1, int(payload.get("batch_size", 1000)))
        fetch_chunk_size = max(1, int(payload.get("fetch_chunk_size", self.config.fetch_chunk_size)))
        delete_after_process = bool(payload.get("delete_after_process", True))
        minimum_import_score = float(payload.get("minimum_import_score", _DEFAULT_MINIMUM_IMPORT_SCORE))

        logger.info(
            "Starting Common Crawl import: crawls=%s country=%s files_per_crawl=%s batch_size=%s fetch_chunk_size=%s "
            "delete_after_process=%s minimum_import_score=%s cache_dir=%s",
            crawls,
            country,
            files_per_crawl,
            batch_size,
            fetch_chunk_size,
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
                    file_stats = self._process_parquet_file(
                        local_path=local_path,
                        crawl_id=crawl_id,
                        country=country,
                        batch_size=batch_size,
                        minimum_import_score=minimum_import_score,
                        fetch_chunk_size=fetch_chunk_size,
                    )
                    rows_read_before_filters += file_stats.rows_read_before_filters
                    rows_skipped_invalid_domain += file_stats.rows_skipped_invalid_domain
                    rows_skipped_country_filter += file_stats.rows_skipped_country_filter
                    rows_accepted_after_country_filter += file_stats.rows_accepted_after_country_filter
                    file_domains = file_stats.domains_extracted
                    total_domains_extracted += file_domains
                    domains_upserted += file_stats.domains_upserted
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
            "countries": [country],
            "country": country,
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
            "fetch_chunk_size": fetch_chunk_size,
            "duration_seconds": duration_seconds,
        }
        logger.info("Common Crawl import summary: %s", summary)

        if files_processed == 0:
            raise RuntimeError("Common Crawl import failed: all parquet files were skipped.")

        return summary
