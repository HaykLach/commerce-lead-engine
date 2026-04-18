"""Search-seed domain discovery service."""

from __future__ import annotations

from dataclasses import dataclass
from urllib.parse import quote_plus

import requests

from lead_crawler.services.domain_extraction_helpers import DomainExtractionHelpers
from lead_crawler.services.domain_normalizer import DomainNormalizer


@dataclass(slots=True)
class DiscoveredDomainCandidate:
    domain: str
    source_type: str
    keyword_seed: str | None
    source_url: str | None


class SearchSourceStrategy:
    """Strategy interface for collecting result pages from keyword seeds."""

    def fetch_result_pages(self, query: str) -> list[tuple[str, str]]:
        raise NotImplementedError


class DuckDuckGoHtmlSearchStrategy(SearchSourceStrategy):
    """Simple HTML source strategy that can be replaced later."""

    def __init__(self, timeout_seconds: float = 10.0) -> None:
        self.timeout_seconds = timeout_seconds

    def fetch_result_pages(self, query: str) -> list[tuple[str, str]]:
        search_url = f"https://duckduckgo.com/html/?q={quote_plus(query)}"

        try:
            response = requests.get(
                search_url,
                timeout=self.timeout_seconds,
                headers={"User-Agent": "Mozilla/5.0"},
            )
            response.raise_for_status()
            return [(search_url, response.text)]
        except requests.RequestException:
            return []


class SearchSeedDiscoveryService:
    COMMERCE_TERMS = ["shop", "store", "wholesale", "distributor", "retailer"]

    def __init__(
        self,
        source_strategy: SearchSourceStrategy | None = None,
        extractor: DomainExtractionHelpers | None = None,
        normalizer: DomainNormalizer | None = None,
    ) -> None:
        self.source_strategy = source_strategy or DuckDuckGoHtmlSearchStrategy()
        self.normalizer = normalizer or DomainNormalizer()
        self.extractor = extractor or DomainExtractionHelpers(self.normalizer)

    def discover(self, keywords: list[str], countries: list[str], limit: int = 100) -> list[DiscoveredDomainCandidate]:
        candidates: dict[str, DiscoveredDomainCandidate] = {}

        for query in self._build_queries(keywords, countries):
            for source_url, html in self.source_strategy.fetch_result_pages(query):
                domains = self.extractor.extract_external_domains(
                    html=html,
                    base_url=source_url,
                    ignore_domains={"duckduckgo.com"},
                )

                for domain in domains:
                    if domain in candidates:
                        continue

                    candidates[domain] = DiscoveredDomainCandidate(
                        domain=domain,
                        source_type="search_seed",
                        keyword_seed=query,
                        source_url=source_url,
                    )

                    if len(candidates) >= max(1, limit):
                        return list(candidates.values())

        return list(candidates.values())

    def _build_queries(self, keywords: list[str], countries: list[str]) -> list[str]:
        normalized_keywords = [item.strip().lower() for item in keywords if str(item).strip()]
        normalized_countries = [item.strip().lower() for item in countries if str(item).strip()]

        queries: list[str] = []
        for keyword in normalized_keywords:
            for country in normalized_countries or [""]:
                for term in self.COMMERCE_TERMS:
                    query = " ".join(part for part in [keyword, country, term] if part)
                    if query:
                        queries.append(query)

        return list(dict.fromkeys(queries))
