"""DuckDB backend for the Common Crawl columnar index.

**How access works — no credentials required**

The CC S3 bucket (``commoncrawl``) allows unauthenticated REST listing::

    GET https://commoncrawl.s3.amazonaws.com/
        ?prefix=cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/
        &max-keys=5
    → XML with exact parquet filenames

We then query each file via its canonical HTTPS URL::

    https://data.commoncrawl.org/cc-index/table/cc-main/warc/
      crawl=CC-MAIN-2025-13/subset=warc/part-00000-xxxx.snappy.parquet

DuckDB issues HTTP range-requests against the parquet footer to apply
predicate pushdown (TLD filter, URL pattern filter) before reading row data.
This avoids downloading whole files.

No AWS account, no S3 credentials, no glob expansion needed.

**CC columnar index schema** (columns we use)::

    url                   VARCHAR   full URL
    url_host_tld          VARCHAR   TLD ("de", "nl", "ae", …)
    url_host_name         VARCHAR   hostname ("example.de")
    fetch_status          INTEGER   HTTP status (200, 301, …)
    content_mime_detected VARCHAR   MIME type ("text/html", …)
    crawl                 VARCHAR   crawl ID
    subset                VARCHAR   subset type ("warc", "wat", …)
"""

from __future__ import annotations

import logging
import xml.etree.ElementTree as ET
from dataclasses import dataclass
from urllib.parse import urlparse

import requests as _requests

from lead_crawler.services.common_crawl_discovery_service import CommonCrawlCandidateRow
from lead_crawler.services.common_crawl_index_api_backend import KNOWN_CRAWLS
from lead_crawler.services.common_crawl_url_pattern_builder import CommonCrawlUrlPatternBuilder
from lead_crawler.services.domain_normalizer import DomainNormalizer

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# CC public endpoints
# ---------------------------------------------------------------------------
# Unauthenticated S3 REST listing (returns XML, no credentials required)
_CC_S3_LISTING = "https://commoncrawl.s3.amazonaws.com/"
# Base URL for individual parquet files (plain HTTPS, no signing required)
_CC_HTTPS_BASE = "https://data.commoncrawl.org/"
# S3 key prefix template for the warc columnar-index partition
_CC_WARC_PREFIX = "cc-index/table/cc-main/warc/crawl={crawl_id}/subset=warc/"

# HTTP statuses considered valid (stored as integers in CC parquet)
_VALID_FETCH_STATUSES = (200, 301, 302)

# S3 XML namespace
_S3_NS = {"s3": "http://s3.amazonaws.com/doc/2006-03-01/"}


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
@dataclass
class CommonCrawlDuckDbConfig:
    """Configuration for the DuckDB backend."""

    crawls: list[str] | None = None
    """CC crawl IDs to query.  Defaults to :data:`KNOWN_CRAWLS` when *None*."""

    dataset_path: str | None = None
    """Explicit parquet path or glob (local or HTTPS).

    * **None** (default): the backend lists actual parquet files from CC's
      public S3 REST endpoint and queries them via HTTPS — no credentials
      needed.
    * Local path: ``"/data/cc-index/part-*.parquet"`` for offline use / CI.
    * Explicit HTTPS URL: bypasses auto-listing."""

    database: str = ":memory:"
    """DuckDB database file.  ``":memory:"`` for an in-process database."""

    install_httpfs: bool = True
    """Auto-install the ``httpfs`` DuckDB extension for HTTPS file access."""

    cc_files_per_crawl: int = 3
    """Number of parquet files to query per crawl.
    Each file is queried via HTTP range-requests; DuckDB's predicate pushdown
    reads only matching row groups.  Increase for more coverage, decrease for
    faster queries."""

    listing_timeout: float = 20.0
    """HTTP timeout (seconds) for the S3 file-listing requests."""


# ---------------------------------------------------------------------------
# Backend
# ---------------------------------------------------------------------------
class CommonCrawlDuckDbBackend:
    """Fetches candidates by querying CC parquet files with DuckDB.

    Implements the :class:`~lead_crawler.services.common_crawl_discovery_service.CommonCrawlBackend`
    protocol — no inheritance required.

    Recommended for large TLDs (.de, .nl) that routinely time out on the
    CDX Index API.
    """

    def __init__(self, config: CommonCrawlDuckDbConfig | None = None) -> None:
        self.config = config or CommonCrawlDuckDbConfig()
        self._normalizer = DomainNormalizer()

    # ------------------------------------------------------------------
    # Public helpers
    # ------------------------------------------------------------------

    @classmethod
    def list_parquet_urls(
        cls,
        crawl_id: str,
        max_files: int = 5,
        timeout: float = 20.0,
    ) -> list[str]:
        """Return HTTPS URLs for the first *max_files* parquet files in *crawl_id*.

        Uses CC's public (unauthenticated) S3 REST listing endpoint — no AWS
        credentials required.

        Example::

            CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13", max_files=3)
            # → ["https://data.commoncrawl.org/cc-index/table/cc-main/warc/
            #      crawl=CC-MAIN-2025-13/subset=warc/part-00000-xxx.parquet", ...]
        """
        prefix = _CC_WARC_PREFIX.format(crawl_id=crawl_id)
        listing_url = f"{_CC_S3_LISTING}?prefix={prefix}&max-keys={max_files + 10}"

        try:
            resp = _requests.get(listing_url, timeout=timeout)
            resp.raise_for_status()
        except _requests.RequestException as exc:
            logger.warning(
                "CC S3 listing failed for crawl %s: %s", crawl_id, exc
            )
            return []

        try:
            root = ET.fromstring(resp.text)
        except ET.ParseError as exc:
            logger.warning("CC S3 listing XML parse error for %s: %s", crawl_id, exc)
            return []

        keys = [
            el.text
            for el in root.findall(".//s3:Key", _S3_NS)
            if el.text and el.text.endswith(".parquet")
        ]
        urls = [f"{_CC_HTTPS_BASE}{key}" for key in keys[:max_files]]
        logger.info(
            "Listed %d parquet file(s) for crawl %s", len(urls), crawl_id
        )
        return urls

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _get_crawls(self) -> list[str]:
        return self.config.crawls if self.config.crawls else KNOWN_CRAWLS

    def _resolve_dataset(self) -> list[str] | str:
        """Return the parquet source(s) for DuckDB.

        * Explicit ``dataset_path`` → returned as-is.
        * Default → enumerate actual parquet URLs from CC's S3 listing.
        """
        if self.config.dataset_path:
            return self.config.dataset_path

        all_urls: list[str] = []
        for crawl_id in self._get_crawls():
            urls = self.list_parquet_urls(
                crawl_id,
                max_files=self.config.cc_files_per_crawl,
                timeout=self.config.listing_timeout,
            )
            all_urls.extend(urls)

        if not all_urls:
            logger.warning(
                "CC parquet file listing returned no files. "
                "Check connectivity to commoncrawl.s3.amazonaws.com."
            )
        return all_urls

    @staticmethod
    def _is_remote(dataset: str | list[str]) -> bool:
        """True if any path requires the httpfs extension (HTTPS / S3)."""
        paths = dataset if isinstance(dataset, list) else [dataset]
        return any(
            str(p).startswith(("https://", "http://", "s3://", "s3a://"))
            for p in paths
        )

    def _build_from_expr(self, dataset: str | list[str]) -> str:
        if isinstance(dataset, list):
            if not dataset:
                raise ValueError("No CC parquet files could be resolved.")
            quoted = ", ".join(f"'{p}'" for p in dataset)
            return f"read_parquet([{quoted}], union_by_name=true)"
        return f"read_parquet('{dataset}', union_by_name=true)"

    def _setup_connection(self, conn: object, dataset: str | list[str]) -> None:
        """Load the httpfs extension for remote file access."""
        if self.config.install_httpfs and self._is_remote(dataset):
            try:
                conn.execute("INSTALL httpfs")
                conn.execute("LOAD httpfs")
            except Exception as exc:  # noqa: BLE001
                logger.warning("DuckDB httpfs setup warning: %s", exc)

    def _build_sql(
        self,
        from_expr: str,
        tld_list: list[str],
        patterns: list[str],
    ) -> str:
        """Build SELECT using CC's real parquet column names."""
        where_parts: list[str] = []

        status_csv = ", ".join(str(s) for s in _VALID_FETCH_STATUSES)
        where_parts.append(f"fetch_status IN ({status_csv})")

        where_parts.append(
            "lower(coalesce(content_mime_detected, '')) LIKE '%text/html%'"
        )

        if tld_list:
            joined = ", ".join(f"'{t.lower()}'" for t in tld_list)
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
        """

    # ------------------------------------------------------------------
    # Protocol method
    # ------------------------------------------------------------------

    def fetch_candidates(
        self,
        patterns: list[str],
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,  # noqa: ARG002
    ) -> list[CommonCrawlCandidateRow]:
        """Query CC parquet files and return up to *limit* deduplicated candidates."""
        try:
            import duckdb  # type: ignore
        except ModuleNotFoundError as exc:
            raise RuntimeError(
                "duckdb package is required for backend=duckdb. "
                "Install it with: pip install duckdb"
            ) from exc

        tld_list = [c.lower() for c in (countries or []) if c.strip()]
        normalized_patterns = CommonCrawlUrlPatternBuilder.normalize_patterns(patterns)
        dataset = self._resolve_dataset()

        if not dataset:
            logger.warning("DuckDB backend: no parquet files resolved — returning empty.")
            return []

        from_expr = self._build_from_expr(dataset)
        sql = self._build_sql(from_expr, tld_list, normalized_patterns)

        logger.debug(
            "DuckDB query: tlds=%s patterns=%s files=%s",
            tld_list,
            normalized_patterns,
            dataset if isinstance(dataset, str) else f"{len(dataset)} file(s)",
        )

        seen_domains: set[str] = set()
        results: list[CommonCrawlCandidateRow] = []
        fetch_limit = max(1, int(limit) * 5)

        with duckdb.connect(self.config.database) as conn:
            self._setup_connection(conn, dataset)
            rows = conn.execute(f"{sql} LIMIT {fetch_limit}").fetchall()

        for url, tld, _host, status, mime, crawl, subset in rows:
            normalized_domain = self._normalizer.normalize(urlparse(url).netloc)
            if not normalized_domain or normalized_domain in seen_domains:
                continue

            matched_pattern = next(
                (p for p in normalized_patterns if p in url.lower()), ""
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
                        "source": str(dataset),
                    },
                )
            )
            if len(results) >= limit:
                break

        logger.info(
            "DuckDB backend returned %d candidates from %d parquet rows",
            len(results), len(rows),
        )
        return results
