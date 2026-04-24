"""DuckDB backend for the Common Crawl columnar index.

Queries CC's public parquet files (or a local copy) directly — no AWS, no
server-side filters, no 504 timeouts.  DuckDB's predicate pushdown limits the
data it reads to the matching Hive partitions (crawl= / subset=).

**Public CC parquet endpoint**::

    https://data.commoncrawl.org/cc-index/table/cc-main/warc/
      crawl=CC-MAIN-2025-13/
        subset=warc/
          part-00000-*.parquet

Use :func:`CommonCrawlDuckDbBackend.cc_parquet_glob` to build the glob URL for
one or more crawl IDs.  Pass it as ``dataset_path`` to the backend.

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
from dataclasses import dataclass, field
from urllib.parse import urlparse

from lead_crawler.services.common_crawl_discovery_service import CommonCrawlCandidateRow
from lead_crawler.services.common_crawl_index_api_backend import KNOWN_CRAWLS
from lead_crawler.services.common_crawl_url_pattern_builder import CommonCrawlUrlPatternBuilder
from lead_crawler.services.domain_normalizer import DomainNormalizer

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Public CC parquet base URL
# ---------------------------------------------------------------------------
_CC_PARQUET_BASE = (
    "https://data.commoncrawl.org/cc-index/table/cc-main/warc"
    "/crawl={crawl_id}/subset=warc/part-*.parquet"
)

# HTTP statuses considered valid (stored as integers in CC parquet)
_VALID_FETCH_STATUSES = (200, 301, 302)


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
@dataclass
class CommonCrawlDuckDbConfig:
    """Configuration for the DuckDB backend."""

    crawls: list[str] | None = None
    """CC crawl IDs to query.  Defaults to :data:`KNOWN_CRAWLS` when *None*."""

    dataset_path: str | None = None
    """Parquet glob path (local or HTTPS).

    * If *None* the public CC HTTPS parquet endpoint is used automatically
      (requires the ``httpfs`` DuckDB extension — installed on first use).
    * Pass a local path like ``"/data/cc-index/part-*.parquet"`` for offline
      use or CI.
    * The value may contain ``*`` globs; DuckDB resolves them."""

    database: str = ":memory:"
    """DuckDB database file.  ``":memory:"`` means an in-process database."""

    install_httpfs: bool = True
    """Automatically ``INSTALL`` and ``LOAD`` the ``httpfs`` extension when
    the dataset path is an HTTPS URL.  Set to *False* if the extension is
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
    def cc_parquet_glob(crawl_ids: list[str] | None = None) -> list[str]:
        """Return public CC HTTPS parquet glob URLs for the given crawl IDs.

        Example::

            CommonCrawlDuckDbBackend.cc_parquet_glob(["CC-MAIN-2025-13"])
            # → ["https://data.commoncrawl.org/cc-index/table/cc-main/warc/
            #      crawl=CC-MAIN-2025-13/subset=warc/part-*.parquet"]
        """
        ids = crawl_ids or KNOWN_CRAWLS
        return [_CC_PARQUET_BASE.format(crawl_id=cid) for cid in ids]

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _get_crawls(self) -> list[str]:
        return self.config.crawls if self.config.crawls else KNOWN_CRAWLS

    def _resolve_dataset(self) -> str | list[str]:
        """Return the parquet source — either the explicit config path or the
        auto-constructed CC HTTPS glob list."""
        if self.config.dataset_path:
            return self.config.dataset_path
        return self.cc_parquet_glob(self._get_crawls())

    def _build_from_expr(self, dataset: str | list[str]) -> str:
        """Build the DuckDB FROM expression for the given dataset."""
        if isinstance(dataset, list):
            # List of globs → pass as JSON array to read_parquet
            quoted = ", ".join(f"'{p}'" for p in dataset)
            return f"read_parquet([{quoted}], hive_partitioning=true, union_by_name=true)"
        return f"read_parquet('{dataset}', hive_partitioning=true, union_by_name=true)"

    @staticmethod
    def _is_https(dataset: str | list[str]) -> bool:
        if isinstance(dataset, list):
            return any(str(p).startswith("https://") for p in dataset)
        return str(dataset).startswith("https://")

    def _setup_connection(self, conn: object, dataset: str | list[str]) -> None:
        """Install/load httpfs extension when querying HTTPS sources."""
        if self.config.install_httpfs and self._is_https(dataset):
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
                coalesce(url_host_tld, '')          AS tld,
                coalesce(url_host_name, '')          AS host,
                coalesce(CAST(fetch_status AS VARCHAR), '') AS status,
                coalesce(content_mime_detected, '')  AS mime,
                coalesce(crawl, '')                  AS crawl,
                coalesce(subset, '')                 AS subset
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
