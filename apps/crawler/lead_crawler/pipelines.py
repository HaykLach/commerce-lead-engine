"""Pipeline placeholders for transforming and persisting crawler items."""

from __future__ import annotations

from typing import Any

from lead_crawler.items import LeadPageItem


class LeadPagePipeline:
    """Placeholder pipeline for lead page processing."""

    def process_item(self, item: LeadPageItem, spider: Any) -> LeadPageItem:
        """Process a crawler item and return it for downstream pipeline steps."""
        return item
