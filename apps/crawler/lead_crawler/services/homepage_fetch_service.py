"""Homepage fetch service placeholder."""

from __future__ import annotations


class HomepageFetchService:
    """Fetches homepage HTML for a given domain."""

    def build_homepage_url(self, domain: str, scheme: str = "https") -> str:
        """Construct the homepage URL for a domain."""
        return f"{scheme}://{domain.strip('/')}"

    def fetch(self, url: str, timeout_seconds: float = 10.0) -> str:
        """Fetch and return the homepage body as a string."""
        _ = (url, timeout_seconds)
        return ""
