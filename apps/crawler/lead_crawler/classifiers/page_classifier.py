"""Page classification placeholder."""

from __future__ import annotations


class PageClassifier:
    """Classifies ecommerce pages into project-specific page types."""

    def classify(self, url: str, html: str) -> str:
        """Return a placeholder label for a crawled page."""
        _ = (url, html)
        return "unknown"
