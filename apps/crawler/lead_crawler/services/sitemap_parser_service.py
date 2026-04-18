"""Sitemap parser service."""

from __future__ import annotations

from xml.etree import ElementTree


class SitemapParserService:
    """Parses sitemap content and extracts candidate URLs for crawling."""

    def extract_urls(self, sitemap_xml: str) -> list[str]:
        """Return URLs parsed from a sitemap document."""
        try:
            root = ElementTree.fromstring(sitemap_xml)
        except ElementTree.ParseError:
            return []

        urls: list[str] = []
        for element in root.iter():
            tag = element.tag.lower().rsplit("}", 1)[-1]
            if tag != "loc":
                continue

            value = (element.text or "").strip()
            if value:
                urls.append(value)

        return list(dict.fromkeys(urls))
