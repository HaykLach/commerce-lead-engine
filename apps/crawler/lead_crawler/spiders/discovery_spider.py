"""Discovery spider placeholders for seed-domain exploration."""

from __future__ import annotations

from collections.abc import Iterable

import scrapy
from scrapy.http import Request, Response

from lead_crawler.items import LeadPageItem


class DomainDiscoverySpider(scrapy.Spider):
    """Placeholder spider for discovering ecommerce domains and key pages."""

    name = "domain_discovery"

    def start_requests(self) -> Iterable[Request]:
        """Build initial requests from configured domain discovery seeds."""
        return []

    def parse(self, response: Response) -> Iterable[LeadPageItem | Request]:
        """Parse responses and emit placeholder items or follow-up requests."""
        _ = response
        return []
