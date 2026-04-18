"""Internal link extraction service."""

from __future__ import annotations

from urllib.parse import urljoin, urlparse, urlunparse

from bs4 import BeautifulSoup


class InternalLinkExtractionService:
    """Extracts normalized internal links from HTML content."""

    def extract(self, html: str, base_url: str) -> list[str]:
        """Return de-duplicated same-domain links discovered in an HTML document."""
        base = urlparse(base_url)
        base_host = self._normalize_host(base.netloc)
        if not base_host:
            return []

        soup = BeautifulSoup(html, "html.parser")
        links: list[str] = []

        for anchor in soup.find_all("a"):
            href = anchor.get("href")
            if not href:
                continue

            href_value = str(href).strip()
            if href_value.startswith(("#", "mailto:", "tel:", "javascript:")):
                continue

            candidate = urlparse(urljoin(base_url, href_value))
            candidate_host = self._normalize_host(candidate.netloc)
            if candidate.scheme not in {"http", "https"}:
                continue

            if candidate_host != base_host:
                continue

            normalized = urlunparse((candidate.scheme, candidate.netloc, candidate.path.rstrip("/"), "", candidate.query, ""))
            links.append(normalized)

        return list(dict.fromkeys(links))

    @staticmethod
    def _normalize_host(host: str) -> str:
        value = host.strip().lower()
        if value.startswith("www."):
            value = value[4:]
        return value
