"""Common Crawl CDX Index API backend for domain discovery."""

from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass

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

# TLDs too large for the CDX Index API — even a filter-free page-seek times out
_OVERSIZED_TLDS: frozenset[str] = frozenset({"com", "net", "org"})


# ---------------------------------------------------------------------------
# Config dataclass
# ---------------------------------------------------------------------------
@dataclass
class CommonCrawlIndexApiConfig:
    """Configuration for the CC CDX Index API backend."""

    crawls: list[str] | None = None
    """CC crawl IDs to query.  Defaults to KNOWN_CRAWLS when None."""

    tld_targets: list[str] | None = None
    """TLDs to query (e.g. ``["de", "nl"]``).
    When *None* the TLDs are derived from the *countries* argument passed to
    :meth:`CommonCrawlIndexApiBackend.fetch_candidates`."""

    requests_per_crawl: int = 3
    """Maximum CDX pages fetched per (crawl × tld) combination.
    All supplied patterns are checked locally per page, so there is no
    per-pattern multiplier on the number of HTTP requests."""

    page_size: int = 100
    """Rows per CDX page (hard cap: 100)."""

    request_delay_seconds: float = 1.0
    """Delay between individual HTTP requests to be a polite crawler."""

    timeout_seconds: float = 45.0
    """Per-request HTTP timeout in seconds."""

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

    **Query strategy — why no server-side filters**

    The CDX index is sorted in SURT order (``de,example)/path``).  The only
    query the server can answer in O(1) is a plain page-seek:

        ``url=*.{tld}&limit=N&page=P``

    Any ``filter=`` parameter — including ``filter=url:``, ``filter=status:``,
    and ``filter=mime:`` — forces the server to walk records sequentially until
    it accumulates ``limit`` *matching* records.  For large TLDs this scan
    spans millions of entries and reliably returns 504.

    We therefore send zero filters and perform all matching (status, MIME,
    path patterns) locally in Python after receiving each page.  The extra
    bandwidth from non-HTML or non-matching records is negligible compared to
    the reliability gain.
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
        """Return TLD list — from config override or derived from *countries*."""
        if self.config.tld_targets:
            return list(self.config.tld_targets)

        tlds: list[str] = []
        seen: set[str] = set()
        for country in countries or []:
            for tld in COUNTRY_TO_TLDS.get(country.lower(), []):
                if tld not in seen:
                    seen.add(tld)
                    tlds.append(tld)

        return tlds if tlds else ["com"]

    def _build_request_url(self, crawl_id: str, tld: str, page: int) -> str:
        """Construct a filter-free CDX page-seek URL.

        No ``filter=`` parameters are used.  The server performs a direct
        O(1) seek to ``page * page_size`` within the ``{tld},`` SURT range
        and streams the next ``page_size`` records.  All filtering is done
        locally by the caller.
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
        """Fetch one CDX page.

        Returns:
            Parsed record list (may be empty).
            *None* signals that pagination for this (crawl, tld) combination
            should be aborted — transport failure or CDX gateway error.
        """
        try:
            resp = requests.get(
                url,
                timeout=self.config.timeout_seconds,
                headers={"User-Agent": self.config.user_agent},
            )
        except requests.RequestException as exc:
            logger.warning("CDX request failed (%s): %s", url, exc)
            return None

        # 404 → this crawl/index doesn't exist; skip cleanly
        if resp.status_code == 404:
            logger.info("CDX index not found (404) for %s — skipping", url)
            return []

        # Gateway errors → skip this (crawl, tld) combination
        if resp.status_code in (502, 503, 504):
            logger.warning(
                "CDX gateway error %d for %s — skipping this combination",
                resp.status_code,
                url,
            )
            return None

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
                logger.debug("CDX JSON parse error on line: %r", line)

        return records

    @staticmethod
    def _is_valid_record(record: dict) -> bool:
        """Local status + MIME check (no server-side filter needed)."""
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

        Iteration order: crawls → tlds → pages.
        All pattern matching is done locally per record — no per-pattern
        outer loop and no server-side ``filter=`` parameters.
        """
        seen_domains: set[str] = set()
        results: list[CommonCrawlCandidateRow] = []

        crawls = self._get_crawls()
        tlds = self._get_tlds(countries)

        # Pre-compute stripped path segments for local matching
        path_segments = [p.strip("/") for p in (patterns or [])]

        for crawl in crawls:
            for tld in tlds:
                if tld in _OVERSIZED_TLDS:
                    logger.warning(
                        "Skipping TLD '.%s' — too large for the CDX Index API "
                        "(use the Athena or DuckDB backend instead).",
                        tld,
                    )
                    continue

                for page in range(self.config.requests_per_crawl):
                    if len(results) >= limit:
                        return results

                    request_url = self._build_request_url(crawl, tld, page)
                    logger.debug("CDX fetch: crawl=%s tld=%s page=%d", crawl, tld, page)
                    records = self._fetch_page(request_url)

                    if records is None:
                        break  # gateway error — skip to next tld

                    logger.debug(
                        "CDX response: crawl=%s tld=%s page=%d records=%d",
                        crawl, tld, page, len(records),
                    )

                    for record in records:
                        if not self._is_valid_record(record):
                            continue

                        candidate_url = record.get("url") or ""

                        # Local path filter — must contain at least one target segment
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

                    # Polite delay between requests
                    if self.config.request_delay_seconds > 0:
                        time.sleep(self.config.request_delay_seconds)

                    # Early-stop: last page was sparse — no more data in this range
                    if len(records) < self.config.page_size / 2:
                        break

        return results
