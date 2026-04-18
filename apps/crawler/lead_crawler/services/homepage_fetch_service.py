"""Homepage fetch service."""

from __future__ import annotations

from typing import Any
from urllib.parse import urljoin

import requests
from bs4 import BeautifulSoup


class HomepageFetchService:
    """Fetches homepage HTML and extracts basic page data for a given domain."""

    def build_homepage_url(self, domain: str, scheme: str = "https") -> str:
        """Construct the homepage URL for a domain."""
        normalized = domain.strip().strip("/")
        if normalized.startswith("http://") or normalized.startswith("https://"):
            return normalized

        return f"{scheme}://{normalized}"

    def fetch(self, domain: str, timeout_seconds: float = 10.0) -> dict[str, Any]:
        """Fetch and return normalized homepage data."""
        primary_url = self.build_homepage_url(domain, scheme="https")
        fallback_url = self.build_homepage_url(domain, scheme="http")

        last_error: str | None = None

        for url in (primary_url, fallback_url):
            try:
                response = requests.get(
                    url,
                    timeout=timeout_seconds,
                    allow_redirects=True,
                    headers={
                        "User-Agent": (
                            "Mozilla/5.0 (X11; Linux x86_64) "
                            "AppleWebKit/537.36 (KHTML, like Gecko) "
                            "Chrome/123.0.0.0 Safari/537.36"
                        )
                    },
                )
                response.raise_for_status()

                html = response.text
                extracted = self.extract_page_data(html, response.url)

                return {
                    "html": html,
                    "headers": dict(response.headers),
                    "cookies": requests.utils.dict_from_cookiejar(response.cookies),
                    "scripts": extracted["scripts"],
                    "stylesheets": extracted["stylesheets"],
                    "links": extracted["links"],
                    "meta": extracted["meta"],
                    "status_code": response.status_code,
                    "final_url": response.url,
                }

            except requests.RequestException as exc:
                last_error = str(exc)

        raise RuntimeError(f"Failed to fetch homepage for {domain}: {last_error}")

    def extract_page_data(self, html: str, base_url: str) -> dict[str, Any]:
        """Extract common page assets and metadata from HTML."""
        soup = BeautifulSoup(html, "html.parser")

        scripts: list[str] = []
        stylesheets: list[str] = []
        links: list[str] = []
        meta: dict[str, str] = {}

        for script in soup.find_all("script"):
            src = script.get("src")
            if src:
                scripts.append(urljoin(base_url, str(src)))

        for link in soup.find_all("link"):
            href = link.get("href")
            rel = link.get("rel") or []

            if href:
                absolute_href = urljoin(base_url, str(href))
                links.append(absolute_href)

                rel_lower = [str(item).lower() for item in rel]
                if "stylesheet" in rel_lower:
                    stylesheets.append(absolute_href)

        for meta_tag in soup.find_all("meta"):
            name = meta_tag.get("name") or meta_tag.get("property")
            content = meta_tag.get("content")

            if name and content:
                meta[str(name).strip().lower()] = str(content).strip()

        return {
            "scripts": scripts,
            "stylesheets": stylesheets,
            "links": links,
            "meta": meta,
        }