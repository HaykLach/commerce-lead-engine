"""Known-domain expansion discovery service."""

from __future__ import annotations

import requests

from lead_crawler.services.domain_extraction_helpers import DomainExtractionHelpers
from lead_crawler.services.domain_normalizer import DomainNormalizer
from lead_crawler.services.search_seed_discovery_service import DiscoveredDomainCandidate


class ExpansionDiscoveryService:
    RELATED_LINK_KEYWORDS = [
        "partner",
        "partners",
        "brand",
        "brands",
        "stockist",
        "retailer",
        "retailers",
        "distributor",
        "distributors",
        "reseller",
    ]

    def __init__(
        self,
        extractor: DomainExtractionHelpers | None = None,
        normalizer: DomainNormalizer | None = None,
    ) -> None:
        self.normalizer = normalizer or DomainNormalizer()
        self.extractor = extractor or DomainExtractionHelpers(self.normalizer)

    def discover(self, domains: list[str], limit: int = 50) -> list[DiscoveredDomainCandidate]:
        normalized_seeds = {d for d in (self.normalizer.normalize(domain) for domain in domains) if d}
        candidates: dict[str, DiscoveredDomainCandidate] = {}

        for seed_domain in normalized_seeds:
            if len(candidates) >= max(1, limit):
                break

            for page_url in self._seed_urls(seed_domain):
                if len(candidates) >= max(1, limit):
                    break

                html = self._fetch_html(page_url)
                if html is None:
                    continue

                discovered = self.extractor.extract_filtered_domains_by_link_text(
                    html=html,
                    base_url=page_url,
                    keywords=self.RELATED_LINK_KEYWORDS,
                    ignore_domains=normalized_seeds,
                )

                for domain in discovered:
                    if domain in candidates:
                        continue

                    candidates[domain] = DiscoveredDomainCandidate(
                        domain=domain,
                        source_type="expansion",
                        keyword_seed=seed_domain,
                        source_url=page_url,
                    )

                    if len(candidates) >= max(1, limit):
                        break

        return list(candidates.values())

    def _fetch_html(self, url: str) -> str | None:
        try:
            response = requests.get(url, timeout=10.0, headers={"User-Agent": "Mozilla/5.0"})
            response.raise_for_status()
            return response.text
        except requests.RequestException:
            return None

    @staticmethod
    def _seed_urls(domain: str) -> list[str]:
        return [
            f"https://{domain}",
            f"https://{domain}/partners",
            f"https://{domain}/brands",
            f"https://{domain}/stockists",
            f"https://{domain}/retailers",
        ]
