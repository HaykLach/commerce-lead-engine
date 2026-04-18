"""Helpers for extracting candidate domains from HTML links."""

from __future__ import annotations

from urllib.parse import urljoin, urlparse

from bs4 import BeautifulSoup

from lead_crawler.services.domain_normalizer import DomainNormalizer


class DomainExtractionHelpers:
    def __init__(self, normalizer: DomainNormalizer | None = None) -> None:
        self.normalizer = normalizer or DomainNormalizer()

    def extract_external_domains(self, html: str, base_url: str, ignore_domains: set[str] | None = None) -> set[str]:
        ignored = ignore_domains or set()
        parsed_base = urlparse(base_url)
        base_domain = self.normalizer.normalize(parsed_base.netloc)

        soup = BeautifulSoup(html or "", "html.parser")
        domains: set[str] = set()

        for anchor in soup.select("a[href]"):
            raw_href = str(anchor.get("href") or "").strip()
            if not raw_href or self._ignore_href(raw_href):
                continue

            absolute_url = urljoin(base_url, raw_href)
            parsed = urlparse(absolute_url)

            if parsed.scheme not in {"http", "https"}:
                continue

            domain = self.normalizer.normalize(parsed.netloc)
            if domain is None:
                continue

            if domain in ignored:
                continue

            if base_domain is not None and domain == base_domain:
                continue

            domains.add(domain)

        return domains

    def extract_filtered_domains_by_link_text(
        self,
        html: str,
        base_url: str,
        keywords: list[str],
        ignore_domains: set[str] | None = None,
    ) -> set[str]:
        ignored = ignore_domains or set()
        parsed_base = urlparse(base_url)
        base_domain = self.normalizer.normalize(parsed_base.netloc)
        keyword_tokens = [token.lower() for token in keywords]

        soup = BeautifulSoup(html or "", "html.parser")
        domains: set[str] = set()

        for anchor in soup.select("a[href]"):
            raw_href = str(anchor.get("href") or "").strip()
            if not raw_href or self._ignore_href(raw_href):
                continue

            text = " ".join(
                [
                    str(anchor.get_text(" ", strip=True) or ""),
                    str(anchor.get("title") or ""),
                    str(anchor.get("aria-label") or ""),
                    raw_href,
                ]
            ).lower()
            if not any(keyword in text for keyword in keyword_tokens):
                continue

            absolute_url = urljoin(base_url, raw_href)
            parsed = urlparse(absolute_url)
            if parsed.scheme not in {"http", "https"}:
                continue

            domain = self.normalizer.normalize(parsed.netloc)
            if domain is None:
                continue

            if domain in ignored:
                continue

            if base_domain is not None and domain == base_domain:
                continue

            domains.add(domain)

        return domains

    @staticmethod
    def _ignore_href(href: str) -> bool:
        lowered = href.lower()
        return lowered.startswith(("#", "mailto:", "tel:", "javascript:"))
