"""Common Crawl based domain discovery service."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Protocol

from lead_crawler.services.domain_normalizer import DomainNormalizer


@dataclass(slots=True)
class CommonCrawlCandidateRow:
    candidate_url: str
    normalized_domain: str
    matched_pattern: str
    source_metadata: dict


@dataclass(slots=True)
class CommonCrawlDiscoveredDomainCandidate:
    domain: str
    source_type: str
    source_url: str
    source_context: dict


class CommonCrawlBackend(Protocol):
    def fetch_candidates(
        self,
        patterns: list[str],
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,
    ) -> list[CommonCrawlCandidateRow]:
        ...


class CommonCrawlDiscoveryService:
    """Coordinates backend lookup and fit filtering for Common Crawl candidates."""

    def __init__(
        self,
        backend: CommonCrawlBackend,
        domain_filter,
        normalizer: DomainNormalizer | None = None,
    ) -> None:
        self.backend = backend
        self.domain_filter = domain_filter
        self.normalizer = normalizer or DomainNormalizer()

    def discover(
        self,
        patterns: list[str],
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,
    ) -> list[CommonCrawlDiscoveredDomainCandidate]:
        rows = self.backend.fetch_candidates(
            patterns=patterns,
            limit=limit,
            countries=countries,
            niches=niches,
        )

        candidates: dict[str, CommonCrawlDiscoveredDomainCandidate] = {}
        for row in rows:
            normalized_domain = self.normalizer.normalize(row.normalized_domain or row.candidate_url)
            if not normalized_domain or normalized_domain in candidates:
                continue

            if not self.domain_filter.should_include(normalized_domain):
                continue

            source_context = {
                "matched_pattern": row.matched_pattern,
                "backend": row.source_metadata.get("backend"),
                "crawl": row.source_metadata,
            }

            candidates[normalized_domain] = CommonCrawlDiscoveredDomainCandidate(
                domain=normalized_domain,
                source_type="common_crawl",
                source_url=row.candidate_url,
                source_context=source_context,
            )

            if len(candidates) >= max(1, limit):
                break

        return list(candidates.values())
