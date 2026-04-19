"""Heuristic fit filter for discovered domains."""

from __future__ import annotations


class CommonCrawlDomainFilter:
    DEFAULT_DENYLIST = {
        "amazon.com",
        "ebay.com",
        "aliexpress.com",
        "etsy.com",
        "walmart.com",
        "target.com",
        "facebook.com",
        "instagram.com",
        "linkedin.com",
        "youtube.com",
        "x.com",
        "google.com",
        "microsoft.com",
        "apple.com",
        "cloudflare.com",
        "shopify.com",
    }

    BLOCKED_SUBSTRING_TOKENS = [
        "marketplace",
        "wikipedia",
        "github",
    ]

    def __init__(self, denylist: set[str] | None = None) -> None:
        self.denylist = set(self.DEFAULT_DENYLIST)
        if denylist:
            self.denylist.update(domain.lower() for domain in denylist)

    def should_include(self, normalized_domain: str) -> bool:
        host = normalized_domain.lower()
        if host in self.denylist:
            return False

        if any(host.endswith(f".{blocked}") for blocked in self.denylist):
            return False

        if any(token in host for token in self.BLOCKED_SUBSTRING_TOKENS):
            return False

        labels = host.split(".")
        if len(labels) < 2:
            return False

        if labels[0] in {"www", "shop", "store"} and len(labels) == 2:
            return True

        return True
