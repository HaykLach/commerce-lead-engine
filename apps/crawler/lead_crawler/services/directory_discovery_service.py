"""Directory/listing page domain discovery service."""

from __future__ import annotations

import requests

from lead_crawler.services.domain_extraction_helpers import DomainExtractionHelpers
from lead_crawler.services.domain_normalizer import DomainNormalizer
from lead_crawler.services.search_seed_discovery_service import DiscoveredDomainCandidate


class DirectoryDiscoveryService:
    def __init__(
        self,
        extractor: DomainExtractionHelpers | None = None,
        normalizer: DomainNormalizer | None = None,
    ) -> None:
        self.normalizer = normalizer or DomainNormalizer()
        self.extractor = extractor or DomainExtractionHelpers(self.normalizer)

    def discover(self, directory_urls: list[str], limit: int = 100) -> list[DiscoveredDomainCandidate]:
        candidates: dict[str, DiscoveredDomainCandidate] = {}

        for directory_url in directory_urls:
            if len(candidates) >= max(1, limit):
                break

            try:
                response = requests.get(directory_url, timeout=10.0, headers={"User-Agent": "Mozilla/5.0"})
                response.raise_for_status()
            except requests.RequestException:
                continue

            domains = self.extractor.extract_external_domains(html=response.text, base_url=directory_url)
            for domain in domains:
                if domain in candidates:
                    continue

                candidates[domain] = DiscoveredDomainCandidate(
                    domain=domain,
                    source_type="directory",
                    keyword_seed=None,
                    source_url=directory_url,
                )

                if len(candidates) >= max(1, limit):
                    break

        return list(candidates.values())
