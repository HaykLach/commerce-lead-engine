"""DuckDB backend for the Common Crawl columnar index."""

from __future__ import annotations

import logging
import time
import os
from dataclasses import dataclass
from urllib.parse import urlparse

from lead_crawler.services.common_crawl_discovery_service import CommonCrawlCandidateRow
from lead_crawler.services.common_crawl_index_api_backend import KNOWN_CRAWLS
from lead_crawler.services.common_crawl_url_pattern_builder import CommonCrawlUrlPatternBuilder
from lead_crawler.services.domain_normalizer import DomainNormalizer

try:
    import boto3  # type: ignore
    from botocore.exceptions import ClientError as _BotoClientError  # type: ignore
    from botocore.exceptions import NoCredentialsError as _BotoNoCredentialsError  # type: ignore
except ImportError:  # pragma: no cover
    boto3 = None  # type: ignore
    _BotoClientError = Exception  # type: ignore
    _BotoNoCredentialsError = Exception  # type: ignore

logger = logging.getLogger(__name__)

_CC_S3_BUCKET = "commoncrawl"
_CC_S3_REGION = "us-east-1"
_CC_HTTPS_BASE = "https://data.commoncrawl.org/"
_CC_WARC_PREFIX = "cc-index/table/cc-main/warc/crawl={crawl_id}/subset=warc/"

_VALID_FETCH_STATUSES = (200, 301, 302)

_CREDENTIALS_HELP = """
AWS credentials are required to list Common Crawl parquet files.

You need s3:ListBucket permission for arn:aws:s3:::commoncrawl.

Alternatively, pass an explicit local path in the job payload:
  "duckdb_dataset_path": "/data/cc-index/part-*.parquet"
""".strip()


@dataclass
class CommonCrawlDuckDbConfig:
    crawls: list[str] | None = None
    dataset_path: str | None = None
    database: str = ":memory:"
    install_httpfs: bool = True
    cc_files_per_crawl: int = 3


class CommonCrawlDuckDbBackend:
    def __init__(self, config: CommonCrawlDuckDbConfig | None = None) -> None:
        self.config = config or CommonCrawlDuckDbConfig()
        self._normalizer = DomainNormalizer()

        logger.info(
            "DuckDB backend config initialized: crawls=%s dataset_path=%s database=%s "
            "install_httpfs=%s cc_files_per_crawl=%s",
            self.config.crawls,
            self.config.dataset_path,
            self.config.database,
            self.config.install_httpfs,
            self.config.cc_files_per_crawl,
        )

    @classmethod
    def list_parquet_urls(cls, crawl_id: str, max_files: int = 5) -> list[str]:
        if boto3 is None:
            raise RuntimeError(
                "boto3 is required for CC parquet file listing. "
                "Install with: pip install boto3\n\n" + _CREDENTIALS_HELP
            )

        prefix = _CC_WARC_PREFIX.format(crawl_id=crawl_id)

        logger.info(
            "Listing Common Crawl parquet files: bucket=%s prefix=%s max_files=%s",
            _CC_S3_BUCKET,
            prefix,
            max_files,
        )

        try:
            s3 = boto3.client("s3", region_name=_CC_S3_REGION)
            resp = s3.list_objects_v2(
                Bucket=_CC_S3_BUCKET,
                Prefix=prefix,
                MaxKeys=max_files + 10,
            )
        except _BotoNoCredentialsError as exc:
            raise RuntimeError(_CREDENTIALS_HELP) from exc
        except _BotoClientError as exc:
            logger.warning("S3 listing failed for crawl %s: %s", crawl_id, exc)
            return []

        keys = [
            obj["Key"]
            for obj in resp.get("Contents", [])
            if obj.get("Key", "").endswith(".parquet")
        ]

        urls = [f"s3://{_CC_S3_BUCKET}/{key}" for key in keys[:max_files]]

        logger.info(
            "Listed %d parquet file(s) for crawl %s",
            len(urls),
            crawl_id,
        )

        for index, url in enumerate(urls, start=1):
            logger.info("Parquet URL %s/%s for %s: %s", index, len(urls), crawl_id, url)

        return urls

    def _get_crawls(self) -> list[str]:
        crawls = self.config.crawls if self.config.crawls else KNOWN_CRAWLS
        logger.info("DuckDB selected crawls: %s", crawls)
        return crawls

    def _resolve_dataset(self) -> list[str] | str:
        if self.config.dataset_path:
            logger.info("Using explicit DuckDB dataset_path: %s", self.config.dataset_path)
            return self.config.dataset_path

        all_urls: list[str] = []

        crawls = self._get_crawls()

        logger.info(
            "Resolving Common Crawl dataset from S3 listing: crawls=%s files_per_crawl=%s",
            crawls,
            self.config.cc_files_per_crawl,
        )

        for crawl_id in crawls:
            urls = self.list_parquet_urls(
                crawl_id,
                max_files=self.config.cc_files_per_crawl,
            )
            all_urls.extend(urls)

        logger.info("Resolved total parquet file count: %d", len(all_urls))

        if not all_urls:
            logger.warning(
                "CC parquet listing returned no files for any crawl. "
                "Check AWS credentials and S3 connectivity."
            )

        return all_urls

    @staticmethod
    def _is_remote(dataset: str | list[str]) -> bool:
        paths = dataset if isinstance(dataset, list) else [dataset]
        return any(
            str(path).startswith(("https://", "http://", "s3://", "s3a://"))
            for path in paths
        )

    def _build_from_expr(self, dataset: str | list[str]) -> str:
        if isinstance(dataset, list):
            quoted = ", ".join(f"'{path}'" for path in dataset)
            return f"read_parquet([{quoted}], union_by_name=true)"

        return f"read_parquet('{dataset}', union_by_name=true)"

    def _setup_connection(self, conn: object, dataset: str | list[str]) -> None:
        if self.config.install_httpfs and self._is_remote(dataset):
            try:
                logger.info("Installing/loading DuckDB httpfs extension...")
                conn.execute("INSTALL httpfs")
                conn.execute("LOAD httpfs")

                conn.execute("SET http_timeout = 600")
                conn.execute("SET http_retries = 5")

                conn.execute("SET s3_region='us-east-1'")
                conn.execute("SET s3_access_key_id=$1", [os.environ["AWS_ACCESS_KEY_ID"]])
                conn.execute("SET s3_secret_access_key=$1", [os.environ["AWS_SECRET_ACCESS_KEY"]])

                logger.info("DuckDB httpfs extension loaded with S3 credentials.")
            except Exception as exc:
                logger.warning("DuckDB httpfs setup warning: %s", exc)

    def _build_sql(
        self,
        from_expr: str,
        tld_list: list[str],
        patterns: list[str],
    ) -> str:
        return f"""
        SELECT
            url,
            coalesce(url_host_tld, '') AS tld,
            coalesce(url_host_name, '') AS host,
            coalesce(CAST(fetch_status AS VARCHAR), '') AS status,
            coalesce(content_mime_detected, '') AS mime,
            coalesce(crawl, '') AS crawl,
            coalesce(subset, '') AS subset
        FROM {from_expr}
        WHERE fetch_status IN (200, 301, 302)
        """.strip()
        where_parts: list[str] = []

        status_csv = ", ".join(str(status) for status in _VALID_FETCH_STATUSES)
        where_parts.append(f"fetch_status IN ({status_csv})")
        where_parts.append(
            "lower(coalesce(content_mime_detected, '')) LIKE '%text/html%'"
        )

        if tld_list:
            joined = ", ".join(f"'{tld.lower()}'" for tld in tld_list)
            where_parts.append(f"lower(coalesce(url_host_tld, '')) IN ({joined})")

        pattern_clause = CommonCrawlUrlPatternBuilder.like_clauses("url", patterns)
        if pattern_clause:
            where_parts.append(f"({pattern_clause})")

        where_sql = " AND ".join(where_parts) if where_parts else "1 = 1"

        return f"""
SELECT
    url,
    coalesce(url_host_tld, '')                 AS tld,
    coalesce(url_host_name, '')                 AS host,
    coalesce(CAST(fetch_status AS VARCHAR), '') AS status,
    coalesce(content_mime_detected, '')         AS mime,
    coalesce(crawl, '')                         AS crawl,
    coalesce(subset, '')                        AS subset
FROM {from_expr}
WHERE {where_sql}
""".strip()

    def _execute_duckdb_query(
        self,
        conn: object,
        dataset: str | list[str],
        tld_list: list[str],
        normalized_patterns: list[str],
        fetch_limit: int,
    ) -> list[tuple]:
        rows: list[tuple] = []

        datasets = dataset if isinstance(dataset, list) else [dataset]

        for index, single_dataset in enumerate(datasets, start=1):
            try:
                from_expr = self._build_from_expr(single_dataset)
                sql = self._build_sql(from_expr, tld_list, normalized_patterns)
                final_sql = f"{sql}\nLIMIT {fetch_limit}"

                logger.info(
                    "DuckDB querying parquet %d/%d: %s",
                    index,
                    len(datasets),
                    single_dataset,
                )
                logger.info("DuckDB SQL:\n%s", final_sql)

                file_rows = conn.execute(final_sql).fetchall()

                logger.info(
                    "DuckDB parquet %d/%d returned %d row(s)",
                    index,
                    len(datasets),
                    len(file_rows),
                )

                rows.extend(file_rows)

                if len(rows) >= fetch_limit:
                    break

            except Exception as exc:  # noqa: BLE001
                logger.warning(
                    "Skipping parquet %d/%d because DuckDB failed: %s | file=%s",
                    index,
                    len(datasets),
                    exc,
                    single_dataset,
                )
                continue

        return rows[:fetch_limit]

    def fetch_candidates(
        self,
        patterns: list[str],
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,  # noqa: ARG002
    ) -> list[CommonCrawlCandidateRow]:
        try:
            import duckdb  # type: ignore
        except ModuleNotFoundError as exc:
            raise RuntimeError(
                "duckdb package is required for backend=duckdb. "
                "Install with: pip install duckdb"
            ) from exc

        started_at = time.perf_counter()

        tld_list = [country.lower() for country in (countries or []) if country.strip()]
        normalized_patterns = CommonCrawlUrlPatternBuilder.normalize_patterns(patterns)

        logger.info(
            "DuckDB fetch_candidates started: patterns=%s normalized_patterns=%s "
            "countries=%s tld_list=%s niches=%s limit=%s",
            patterns,
            normalized_patterns,
            countries,
            tld_list,
            niches,
            limit,
        )

        dataset = self._resolve_dataset()

        if not dataset:
            logger.warning("DuckDB backend: no parquet files resolved — returning empty.")
            return []

        from_expr = self._build_from_expr(dataset)
        sql = self._build_sql(from_expr, tld_list, normalized_patterns)

        fetch_limit = max(1, int(limit) * 5)
        final_sql = f"{sql}\nLIMIT {fetch_limit}"

        source_description = (
            f"{len(dataset)} file(s)" if isinstance(dataset, list) else dataset
        )

        logger.info(
            "DuckDB query prepared: source=%s fetch_limit=%s result_limit=%s",
            source_description,
            fetch_limit,
            limit,
        )

        logger.info("DuckDB SQL executing:\n%s", final_sql)

        seen_domains: set[str] = set()
        results: list[CommonCrawlCandidateRow] = []

        with duckdb.connect(self.config.database) as conn:
            self._setup_connection(conn, dataset)

            query_started_at = time.perf_counter()
            logger.info("DuckDB query started...")

            rows = self._execute_duckdb_query(
                conn=conn,
                dataset=dataset,
                tld_list=tld_list,
                normalized_patterns=normalized_patterns,
                fetch_limit=fetch_limit,
            )

            query_duration = time.perf_counter() - query_started_at

            logger.info(
                "DuckDB query finished: rows=%d duration=%.2fs",
                len(rows),
                query_duration,
            )

        for row_index, (url, tld, _host, status, mime, crawl, subset) in enumerate(
            rows,
            start=1,
        ):
            if row_index <= 10:
                logger.info(
                    "DuckDB row sample %d: url=%s tld=%s status=%s mime=%s crawl=%s subset=%s",
                    row_index,
                    url,
                    tld,
                    status,
                    mime,
                    crawl,
                    subset,
                )

            normalized_domain = self._normalizer.normalize(urlparse(url).netloc)

            if not normalized_domain or normalized_domain in seen_domains:
                continue

            matched_pattern = next(
                (pattern for pattern in normalized_patterns if pattern in url.lower()),
                "",
            )

            seen_domains.add(normalized_domain)

            results.append(
                CommonCrawlCandidateRow(
                    candidate_url=url,
                    normalized_domain=normalized_domain,
                    matched_pattern=matched_pattern,
                    source_metadata={
                        "backend": "duckdb",
                        "tld": tld or None,
                        "status": status or None,
                        "mime": mime or None,
                        "crawl": crawl or None,
                        "subset": subset or None,
                        "source": source_description,
                    },
                )
            )

            if len(results) >= limit:
                break

        total_duration = time.perf_counter() - started_at

        logger.info(
            "DuckDB backend returned %d candidates from %d parquet rows in %.2fs",
            len(results),
            len(rows),
            total_duration,
        )

        return results