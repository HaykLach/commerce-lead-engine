"""DuckDB backend for the Common Crawl columnar index.

Queries CC's public S3 parquet files directly — no AWS account needed, no
server-side filters, no 504 timeouts.  DuckDB's S3 client calls ListObjects to
enumerate files matching the glob, then uses predicate pushdown to limit reads
to matching Hive partitions (crawl= / subset=).

**Why S3, not HTTPS**

DuckDB's HTTPS client treats the URL as a single file path.  A glob like
``part-*.parquet`` is sent verbatim, returning HTTP 404 (there is no directory
listing endpoint).  The S3 API supports ``ListObjects``, so DuckDB can
enumerate all matching parquet files before reading them.

**Public CC S3 path**::

    s3://commoncrawl/cc-index/table/cc-main/warc/
      crawl=CC-MAIN-2025-13/
        subset=warc/
          part-*.parquet

The ``commoncrawl`` bucket is publicly readable — no AWS credentials needed.
DuckDB's httpfs extension is configured for anonymous access automatically.

**CC columnar index schema** (columns we use)::

    url                   VARCHAR   full URL
    url_host_tld          VARCHAR   TLD ("de", "nl", "ae", …)
    url_host_name         VARCHAR   hostname ("example.de")
    fetch_status          INTEGER   HTTP status (200, 301, …)
    content_mime_detected VARCHAR   MIME type ("text/html", …)
    crawl                 VARCHAR   crawl ID (Hive partition)
    subset                VARCHAR   subset type ("warc", "wat", …)
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from urllib.parse import urlparse

from lead_crawler.services.common_crawl_discovery_service import CommonCrawlCandidateRow
from lead_crawler.services.common_crawl_index_api_backend import KNOWN_CRAWLS
from lead_crawler.services.common_crawl_url_pattern_builder import CommonCrawlUrlPatternBuilder
from lead_crawler.services.domain_normalizer import DomainNormalizer

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# CC public S3 path template  (bucket: commoncrawl, region: us-east-1)
# ---------------------------------------------------------------------------
_CC_S3_BASE = (
    "s3://commoncrawl/cc-index/table/cc-main/warc"
    "/crawl={crawl_id}/subset=warc/*.parquet"
)

# HTTP statuses considered valid (stored as integers in CC parquet)
_VALID_FETCH_STATUSES = (200, 301, 302)

# CC S3 bucket region (always us-east-1)
_CC_S3_REGION = "us-east-1"


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
@dataclass
class CommonCrawlDuckDbConfig:
    """Configuration for the DuckDB backend."""

    crawls: list[str] | None = None
    """CC crawl IDs to query.  Defaults to :data:`KNOWN_CRAWLS` when *None*."""

    dataset_path: str | None = None
    """Parquet glob path (local path or ``s3://`` URL).

    * If *None* the public CC S3 paths are used automatically
      (DuckDB's httpfs extension is installed on first use; anonymous access
      to the public ``commoncrawl`` bucket is configured automatically).
    * Pass a local path like ``"/data/cc-index/part-*.parquet"`` for offline
      use or CI.
    * Pass an ``s3://`` URL to use a private or mirrored bucket."""

    database: str = ":memory:"
    """DuckDB database file.  ``":memory:"`` means an in-process database."""

    install_httpfs: bool = True
    """Automatically ``INSTALL`` and ``LOAD`` the ``httpfs`` DuckDB extension
    when an S3 or HTTPS dataset is used.  Set *False* if the extension is
    already installed in the DuckDB environment."""


# ---------------------------------------------------------------------------
# Backend
# ---------------------------------------------------------------------------
class CommonCrawlDuckDbBackend:
    """Fetches candidates by querying CC parquet files with DuckDB.

    Implements the :class:`~lead_crawler.services.common_crawl_discovery_service.CommonCrawlBackend`
    protocol — no inheritance required.

    This backend is the recommended choice for large TLDs (.de, .nl) that
    routinely time out on the CDX Index API.  DuckDB's predicate pushdown
    limits parquet reads to only the required Hive partitions and row groups.
    """

    def __init__(self, config: CommonCrawlDuckDbConfig | None = None) -> None:
        self.config = config or CommonCrawlDuckDbConfig()
        self._normalizer = DomainNormalizer()

    # ------------------------------------------------------------------
    # Public helpers
    # ------------------------------------------------------------------

    @staticmethod
    def cc_s3_globs(crawl_ids: list[str] | None = None) -> list[str]:
        """Return public CC S3 parquet glob paths for the given crawl IDs.

        Example::

            CommonCrawlDuckDbBackend.cc_s3_globs(["CC-MAIN-2025-13"])
            # → ["s3://commoncrawl/cc-index/table/cc-main/warc/
            #      crawl=CC-MAIN-2025-13/subset=warc/*.parquet"]
        """
        ids = crawl_ids or KNOWN_CRAWLS
        return [_CC_S3_BASE.format(crawl_id=cid) for cid in ids]

    # backward-compat alias
    @staticmethod
    def cc_parquet_glob(crawl_ids: list[str] | None = None) -> list[str]:  # noqa: D102
        return CommonCrawlDuckDbBackend.cc_s3_globs(crawl_ids)

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _get_crawls(self) -> list[str]:
        return self.config.crawls if self.config.crawls else KNOWN_CRAWLS

    def _resolve_dataset(self) -> str | list[str]:
        if self.config.dataset_path:
            return self.config.dataset_path
        return self.cc_s3_globs(self._get_crawls())

    def _build_from_expr(self, dataset: str | list[str]) -> str:
        if isinstance(dataset, list):
            quoted = ", ".join(f"'{p}'" for p in dataset)
            return f"read_parquet([{quoted}], hive_partitioning=true, union_by_name=true)"
        return f"read_parquet('{dataset}', hive_partitioning=true, union_by_name=true)"

    @staticmethod
    def _is_remote(dataset: str | list[str]) -> bool:
        """True when the dataset path(s) require the httpfs extension (S3 or HTTPS)."""
        paths = dataset if isinstance(dataset, list) else [dataset]
        return any(str(p).startswith(("s3://", "s3a://", "https://", "http://")) for p in paths)

    @staticmethod
    def _is_https(dataset: str | list[str]) -> bool:
        paths = dataset if isinstance(dataset, list) else [dataset]
        return any(str(p).startswith("https://") for p in paths)

    def _setup_connection(self, conn: object, dataset: str | list[str]) -> None:
        """Configure DuckDB for remote access.

        * S3 paths: installs httpfs and configures anonymous read access to the
          public ``commoncrawl`` S3 bucket (region us-east-1, no credentials).
        * HTTPS paths: installs httpfs and enables HTTP glob support.
        * Local paths: no-op.
        """
        if not (self.config.install_httpfs and self._is_remote(dataset)):
            return

        try:
            conn.execute("INSTALL httpfs")
            conn.execute("LOAD httpfs")
        except Exception as exc:  # noqa: BLE001
            logger.warning("DuckDB httpfs install/load warning: %s", exc)

        if not self._is_https(dataset):
            # S3 path — configure anonymous access to the public CC bucket.
            # DuckDB 1.x uses the Secrets API; fall back to legacy SET commands.
            try:
                conn.execute(f"""
                    CREATE OR REPLACE SECRET cc_public_s3 (
                        TYPE    S3,
                        KEY_ID  '',
                        SECRET  '',
                        REGION  '{_CC_S3_REGION}'
                    )
                """)
            except Exception:  # noqa: BLE001
                # Older DuckDB versions (<0.10) don't have the Secrets API.
                try:
                    conn.execute(f"SET s3_region='{_CC_S3_REGION}'")
                    conn.execute("SET s3_access_key_id=''")
                    conn.execute("SET s3_secret_access_key=''")
                except Exception as exc2:  # noqa: BLE001
                    logger.warning("DuckDB S3 anonymous config warning: %s", exc2)
        else:
            # Plain HTTPS — enable glob support (DuckDB disables it by default).
            try:
                conn.execute("SET allow_asterisks_in_http_paths = true")
            except Exception as exc:  # noqa: BLE001
                logger.warning("DuckDB HTTP glob config warning: %s", exc)

    def _build_sql(
        self,
        from_expr: str,
        tld_list: list[str],
        patterns: list[str],
    ) -> str:
        """Build the SELECT query using CC's real parquet column names."""
        where_parts: list[str] = []

        # Status filter (fetch_status is INTEGER in CC parquet)
        status_csv = ", ".join(str(s) for s in _VALID_FETCH_STATUSES)
        where_parts.append(f"fetch_status IN ({status_csv})")

        # MIME filter
        where_parts.append(
            "lower(coalesce(content_mime_detected, '')) LIKE '%text/html%'"
        )

        # TLD filter (url_host_tld is a string like "de")
        if tld_list:
            joined = ", ".join(f"'{t.lower()}'" for t in tld_list)
            where_parts.append(f"lower(coalesce(url_host_tld, '')) IN ({joined})")

        # URL path-pattern filter
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
        niches: list[str] | None = None,  # noqa: ARG002 — kept for protocol compat
    ) -> list[CommonCrawlCandidateRow]:
        """Query CC parquet and return up to *limit* deduplicated candidates.

        Args:
            patterns: URL path segments to match (e.g. ``["/products/", "/checkout"]``).
            limit: Maximum number of results to return.
            countries: ISO country codes used as TLD targets (e.g. ``["de", "nl"]``).
            niches: Ignored (kept for protocol compatibility).
        """
        try:
            import duckdb  # type: ignore
        except ModuleNotFoundError as exc:
            raise RuntimeError(
                "duckdb package is required for backend=duckdb.  "
                "Install it with: pip install duckdb"
            ) from exc

        tld_list = [c.lower() for c in (countries or []) if c.strip()]
        normalized_patterns = CommonCrawlUrlPatternBuilder.normalize_patterns(patterns)
        dataset = self._resolve_dataset()
        from_expr = self._build_from_expr(dataset)
        sql = self._build_sql(from_expr, tld_list, normalized_patterns)

        logger.debug(
            "DuckDB query: tlds=%s patterns=%s dataset=%s",
            tld_list, normalized_patterns, dataset,
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
