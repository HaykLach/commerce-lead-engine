"""Common Crawl CDX Index API backend for domain discovery."""

from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass, field

import requests

from lead_crawler.services.common_crawl_discovery_service import CommonCrawlCandidateRow
from lead_crawler.services.domain_normalizer import DomainNormalizer

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Hardcoded crawl IDs (4 most recent as of project inception)
# ---------------------------------------------------------------------------
KNOWN_CRAWLS: list[str] = [
    "CC-MAIN-2025-13",
    "CC-MAIN-2024-51",
    "CC-MAIN-2024-46",
    "CC-MAIN-2024-42",
]

# ---------------------------------------------------------------------------
# Country → ccTLD mapping for automatic TLD derivation
# ---------------------------------------------------------------------------
COUNTRY_TO_TLDS: dict[str, list[str]] = {
    "de": ["de"],
    "at": ["at"],
    "ch": ["ch"],
    "nl": ["nl"],
    "se": ["se"],
    "us": ["us"],   # .com omitted — too large for the CDX Index API
    "ae": ["ae"],
}

# CDX API base URL template
_CDX_BASE = "https://index.commoncrawl.org/{crawl_id}-index"

# HTTP statuses we consider valid (checked locally after fetch)
_VALID_STATUSES: frozenset[str] = frozenset({"200", "301", "302"})

# TLDs with so many crawled pages that even a filter-free *.{tld} page-seek
# exceeds the CDX server's 30-second gateway timeout.  Queries are skipped
# automatically unless the caller explicitly sets tld_targets to include them.
# Use the Athena or DuckDB backend for reliable discovery in these TLDs.
_OVERSIZED_TLDS: frozenset[str] = frozenset({"com", "net", "org", "de", "nl"})

# TLDs that are large but usually manageable with the CDX API — a warning is
# logged and the request is attempted; retries handle transient timeouts.
_LARGE_TLDS: frozenset[str] = frozenset({"fr", "it", "es", "pl", "ru", "br"})


# ---------------------------------------------------------------------------
# Config dataclass
# ---------------------------------------------------------------------------
@dataclass
class CommonCrawlIndexApiConfig:
    """Configuration for the CC CDX Index API backend."""

    crawls: list[str] | None = None
    """CC crawl IDs to query.  Defaults to KNOWN_CRAWLS when None."""

    tld_targets: list[str] | None = None
    """TLDs to query (e.g. ``["ae", "ch"]``).
    When *None* the TLDs are derived from the *countries* argument passed to
    :meth:`CommonCrawlIndexApiBackend.fetch_candidates`.

    Note: large TLDs such as ``.de`` and ``.nl`` are skipped automatically
    when derived from countries.  To query them anyway, set this field
    explicitly — but expect frequent 504 timeouts; the Athena or DuckDB
    backend is strongly preferred for those TLDs."""

    requests_per_crawl: int = 3
    """Maximum CDX pages fetched per (crawl × tld) combination.
    All supplied patterns are checked locally per page — no per-pattern
    multiplier on HTTP request count."""

    page_size: int = 100
    """Rows per CDX page (hard cap: 100)."""

    request_delay_seconds: float = 1.0
    """Delay between individual HTTP requests (polite crawling)."""

    timeout_seconds: float = 60.0
    """Per-request HTTP timeout.  The CDX server's own gateway timeout is
    ~30 s; 60 s gives room for slow responses without waiting indefinitely."""

    max_retries: int = 2
    """How many times to retry a 502/503/504 before giving up on a page.
    Retries use exponential back-off starting at ``retry_base_delay_seconds``."""

    retry_base_delay_seconds: float = 8.0
    """Base delay for the first retry (doubles on each subsequent attempt)."""

    user_agent: str = "Mozilla/5.0 (compatible; LeadCrawlerBot/1.0)"
    """HTTP User-Agent header sent with every CDX request."""

    def __post_init__(self) -> None:
        self.page_size = min(self.page_size, 100)


# ---------------------------------------------------------------------------
# Backend implementation
# ---------------------------------------------------------------------------
class CommonCrawlIndexApiBackend:
    """Fetches candidate URLs from the Common Crawl CDX Index API.

    Implements the :class:`~lead_crawler.services.common_crawl_discovery_service.CommonCrawlBackend`
    protocol — no inheritance required.

    **Query strategy — filter-free page-seeks**

    The CDX index is sorted in SURT order (``de,example)/path``).  Any
    ``filter=`` parameter forces an O(n) sequential scan — the server walks
    records until it accumulates ``limit`` matching entries.  For large TLDs
    this reliably exceeds the server's 30-second gateway timeout and returns
    504.

    We therefore send zero filters and do all matching (status, MIME, path
    patterns) locally in Python after each page arrives.  The CDX server only
    needs to seek to ``page * page_size`` in the sorted TLD range and stream
    ``page_size`` records — an O(1) operation.

    **TLD size tiers**

    * ``_OVERSIZED_TLDS`` (.com, .net, .org, .de, .nl): skipped by default;
      the index is too large for the CDX API even without filters.  Use the
      Athena or DuckDB backend for these.
    * ``_LARGE_TLDS`` (.fr, .it, …): attempted with a warning; retries handle
      transient timeouts.
    * Everything else (.ae, .ch, .at, .se, .us): usually fast and reliable.
    """

    def __init__(self, config: CommonCrawlIndexApiConfig) -> None:
        self.config = config
        self._normalizer = DomainNormalizer()

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _get_crawls(self) -> list[str]:
        return self.config.crawls if self.config.crawls else KNOWN_CRAWLS

    def _get_tlds(self, countries: list[str] | None) -> list[str]:
        """Return TLD list — from config override or derived from *countries*.

        When deriving from countries, oversized TLDs are silently excluded
        (they are almost always too slow for the CDX API).  When the caller
        provides an explicit ``tld_targets`` override they bypass this guard —
        useful for testing or when only one crawl/page is needed.
        """
        if self.config.tld_targets:
            return list(self.config.tld_targets)

        tlds: list[str] = []
        seen: set[str] = set()
        for country in countries or []:
            for tld in COUNTRY_TO_TLDS.get(country.lower(), []):
                if tld in _OVERSIZED_TLDS:
                    logger.warning(
                        "TLD '.%s' is too large for the CDX Index API and is "
                        "skipped (use the Athena or DuckDB backend for '%s' discovery, "
                        "or set cc_tld_targets explicitly to force it).",
                        tld, tld,
                    )
                    continue
                if tld not in seen:
                    seen.add(tld)
                    tlds.append(tld)

        return tlds if tlds else []

    def _build_request_url(self, crawl_id: str, tld: str, page: int) -> str:
        """Construct a filter-free CDX page-seek URL.

        No ``filter=`` parameters.  The server does a direct seek to
        ``page * page_size`` within the ``{tld},`` SURT range and streams
        the next ``page_size`` records.  All filtering is done locally.
        """
        base = _CDX_BASE.format(crawl_id=crawl_id)
        parts = [
            f"url=*.{tld}",
            "output=json",
            f"limit={self.config.page_size}",
            "fl=url,status,mime,languages",
            f"page={page}",
        ]
        return f"{base}?" + "&".join(parts)

    def _fetch_page(self, url: str) -> list[dict] | None:
        """Fetch one CDX page, retrying on gateway errors.

        Returns:
            Parsed record list (may be empty).
            *None* means all retries were exhausted — skip this (crawl, tld).
        """
        for attempt in range(self.config.max_retries + 1):
            result = self._fetch_page_once(url)

            if result is not None:          # success or 404-empty
                return result

            # result is None → gateway error; retry if attempts remain
            if attempt < self.config.max_retries:
                delay = self.config.retry_base_delay_seconds * (2 ** attempt)
                logger.info(
                    "CDX retry %d/%d in %.0fs for %s",
                    attempt + 1, self.config.max_retries, delay, url,
                )
                time.sleep(delay)

        logger.warning(
            "CDX gave up after %d retries for %s — skipping this combination. "
            "If this TLD is large (.de, .nl), use the Athena or DuckDB backend instead.",
            self.config.max_retries, url,
        )
        return None

    def _fetch_page_once(self, url: str) -> list[dict] | None:
        """Single HTTP attempt.  Returns record list, empty list, or None on error."""
        try:
            resp = requests.get(
                url,
                timeout=self.config.timeout_seconds,
                headers={"User-Agent": self.config.user_agent},
            )
        except requests.RequestException as exc:
            logger.warning("CDX request failed (%s): %s", url, exc)
            return None

        if resp.status_code == 404:
            logger.info("CDX index not found (404) for %s — skipping", url)
            return []   # not an error, just no data

        if resp.status_code in (502, 503, 504):
            logger.warning(
                "CDX gateway error %d for %s",
                resp.status_code, url,
            )
            return None  # signals retry / give-up

        try:
            resp.raise_for_status()
        except requests.RequestException as exc:
            logger.warning("CDX HTTP error (%s): %s", url, exc)
            return None

        records: list[dict] = []
        for line in resp.text.splitlines():
            line = line.strip()
            if not line:
                continue
            try:
                records.append(json.loads(line))
            except json.JSONDecodeError:
                logger.debug("CDX JSON parse error: %r", line)

        return records

    @staticmethod
    def _is_valid_record(record: dict) -> bool:
        """Local status + MIME check."""
        status = str(record.get("status", "")).strip()
        mime = str(record.get("mime", "")).lower()
        if status not in _VALID_STATUSES:
            return False
        if "text/html" not in mime and "application/xhtml" not in mime:
            return False
        return True

    @staticmethod
    def _match_pattern(url: str, path_segments: list[str]) -> str | None:
        """Return the first pattern whose path segment appears in *url*, or None."""
        url_lower = url.lower()
        for seg in path_segments:
            if f"/{seg}" in url_lower:
                return seg
        return None

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
        """Query the CDX API and return up to *limit* deduplicated candidates.

        Iteration: crawls → tlds → pages.
        All pattern / status / MIME matching is local — zero server-side filters.
        """
        seen_domains: set[str] = set()
        results: list[CommonCrawlCandidateRow] = []

        crawls = self._get_crawls()
        tlds = self._get_tlds(countries)

        if not tlds:
            logger.warning(
                "No queryable TLDs remain after filtering out oversized ones. "
                "Either all requested countries map to large TLDs (.de, .nl, .com) "
                "or no countries were supplied.  "
                "Set cc_tld_targets explicitly (e.g. ['ae','ch','at']) or switch "
                "to the Athena/DuckDB backend for large-TLD discovery."
            )
            return results

        path_segments = [p.strip("/") for p in (patterns or [])]

        for crawl in crawls:
            for tld in tlds:
                if tld in _OVERSIZED_TLDS:
                    logger.warning(
                        "Skipping TLD '.%s' — too large for the CDX Index API even "
                        "without filters (use the Athena or DuckDB backend instead).",
                        tld,
                    )
                    continue

                if tld in _LARGE_TLDS:
                    logger.warning(
                        "TLD '.%s' is large — CDX queries may be slow or time out. "
                        "Consider using the Athena or DuckDB backend for this TLD.",
                        tld,
                    )

                for page in range(self.config.requests_per_crawl):
                    if len(results) >= limit:
                        return results

                    request_url = self._build_request_url(crawl, tld, page)
                    logger.debug("CDX fetch: crawl=%s tld=%s page=%d", crawl, tld, page)
                    records = self._fetch_page(request_url)

                    if records is None:
                        break  # all retries exhausted — move to next tld

                    logger.debug(
                        "CDX response: crawl=%s tld=%s page=%d records=%d",
                        crawl, tld, page, len(records),
                    )

                    for record in records:
                        if not self._is_valid_record(record):
                            continue

                        candidate_url = record.get("url") or ""
                        matched_seg = self._match_pattern(candidate_url, path_segments)
                        if matched_seg is None:
                            continue

                        normalized = self._normalizer.normalize(candidate_url)
                        if not normalized or normalized in seen_domains:
                            continue

                        seen_domains.add(normalized)
                        results.append(
                            CommonCrawlCandidateRow(
                                candidate_url=candidate_url,
                                normalized_domain=normalized,
                                matched_pattern=f"/{matched_seg}",
                                source_metadata={
                                    "backend": "cc_index_api",
                                    "crawl": crawl,
                                    "tld_target": tld,
                                    "status": record.get("status"),
                                    "mime": record.get("mime"),
                                    "languages": record.get("languages"),
                                },
                            )
                        )

                        if len(results) >= limit:
                            return results

                    if self.config.request_delay_seconds > 0:
                        time.sleep(self.config.request_delay_seconds)

                    if len(records) < self.config.page_size / 2:
                        break  # sparse page → no more data in this range

        return results
