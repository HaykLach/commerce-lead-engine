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
    "us": ["com", "us"],
    "ae": ["ae"],
}

# CDX API base URL template
_CDX_BASE = "https://index.commoncrawl.org/{crawl_id}-index"

# HTTP statuses we keep
_VALID_STATUSES: frozenset[str] = frozenset({"200", "301", "302"})


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
    """Maximum CDX pages fetched per (crawl × tld × pattern) combination."""

    page_size: int = 100
    """Rows per CDX page (hard cap: 100)."""

    request_delay_seconds: float = 1.0
    """Delay between individual HTTP requests to be a polite crawler."""

    timeout_seconds: float = 20.0
    """Requests timeout in seconds."""

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

        # Fall back to .com when no mapping found
        return tlds if tlds else ["com"]

    def _build_request_url(self, crawl_id: str, tld: str, pattern: str, page: int) -> str:
        """Construct the CDX API request URL for one page."""
        # Strip surrounding slashes so we build a clean path segment
        path = pattern.strip("/")
        url_pattern = f"*.{tld}/{path}/*"
        base = _CDX_BASE.format(crawl_id=crawl_id)
        return (
            f"{base}"
            f"?url={url_pattern}"
            f"&output=json"
            f"&limit={self.config.page_size}"
            f"&fl=url,status,mime,languages"
            f"&page={page}"
        )

    def _fetch_page(self, url: str) -> list[dict] | None:
        """Fetch one CDX page.

        Returns:
            Parsed record list (may be empty).
            *None* signals an unrecoverable transport error — the caller should
            stop paginating for this (crawl, tld, pattern) combination.
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

        if resp.status_code == 404:
            logger.info("CDX index not found (404) for %s — skipping", url)
            return []

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
        """Return True when status and MIME type are acceptable."""
        status = str(record.get("status", "")).strip()
        mime = str(record.get("mime", "")).lower()

        if status not in _VALID_STATUSES:
            return False

        if "text/html" not in mime and "application/xhtml" not in mime:
            return False

        return True

    # ------------------------------------------------------------------
    # Protocol method
    # ------------------------------------------------------------------

    def fetch_candidates(
        self,
        patterns: list[str],
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,  # noqa: ARG002 — unused, kept for protocol compat
    ) -> list[CommonCrawlCandidateRow]:
        """Query the CDX API and return up to *limit* deduplicated candidates.

        Outer iteration order: crawls → tlds → patterns → pages.
        Stops as soon as *limit* unique domains have been collected.
        """
        seen_domains: set[str] = set()
        results: list[CommonCrawlCandidateRow] = []

        crawls = self._get_crawls()
        tlds = self._get_tlds(countries)

        for crawl in crawls:
            for tld in tlds:
                for pattern in patterns:
                    for page in range(self.config.requests_per_crawl):
                        if len(results) >= limit:
                            return results

                        request_url = self._build_request_url(crawl, tld, pattern, page)
                        logger.info(
                            "CC Index request crawl=%s tld=%s pattern=%s page=%s url=%s",
                            crawl,
                            tld,
                            pattern,
                            page,
                            request_url,
                        )
                        records = self._fetch_page(request_url)

                        if records is None:
                            # Transport error — abort pagination for this combo
                            break

                        logger.info(
                            "CC Index response crawl=%s tld=%s pattern=%s page=%s records=%s",
                            crawl,
                            tld,
                            pattern,
                            page,
                            len(records),
                        )

                        for record in records:
                            if not self._is_valid_record(record):
                                continue

                            candidate_url = record.get("url") or ""
                            normalized = self._normalizer.normalize(candidate_url)
                            if not normalized or normalized in seen_domains:
                                continue

                            seen_domains.add(normalized)
                            results.append(
                                CommonCrawlCandidateRow(
                                    candidate_url=candidate_url,
                                    normalized_domain=normalized,
                                    matched_pattern=pattern,
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

                        # Early-stop: page returned fewer than half the expected rows
                        if len(records) < self.config.page_size / 2:
                            break

        return results
