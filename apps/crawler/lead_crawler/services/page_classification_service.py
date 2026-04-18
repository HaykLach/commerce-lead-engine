"""Domain-level page classification service."""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from urllib.parse import urlparse

import requests

from lead_crawler.classifiers.page_classifier import PageClassifier
from lead_crawler.services.homepage_fetch_service import HomepageFetchService
from lead_crawler.services.internal_link_extraction_service import InternalLinkExtractionService
from lead_crawler.services.sitemap_parser_service import SitemapParserService


@dataclass(slots=True)
class DomainPageClassification:
    domain: str
    product_page_found: bool
    category_page_found: bool
    cart_page_found: bool
    checkout_page_found: bool
    sample_product_url: str | None
    sample_category_url: str | None
    sample_cart_url: str | None
    sample_checkout_url: str | None
    product_count_guess: int | None
    product_count_bucket: str | None
    classification_metadata: dict
    classified_at: str


class PageClassificationService:
    """Fetches and classifies a small set of same-domain pages."""

    def __init__(self) -> None:
        self.fetcher = HomepageFetchService()
        self.link_extractor = InternalLinkExtractionService()
        self.classifier = PageClassifier()
        self.sitemap_parser = SitemapParserService()

    def classify_domain(self, domain: str, max_pages: int = 12) -> DomainPageClassification:
        homepage = self.fetcher.fetch(domain)
        homepage_url = homepage.get("final_url") or self.fetcher.build_homepage_url(domain)

        discovered_links = self.link_extractor.extract(homepage.get("html") or "", homepage_url)
        discovered_links.extend(homepage.get("links") or [])
        same_domain_links = self._same_domain_links(discovered_links, homepage_url)
        sampled_urls = self._sample_urls(homepage_url, same_domain_links, max_pages=max_pages)

        product_url = None
        category_url = None
        cart_url = None
        checkout_url = None

        pages_scanned = []
        html_documents = [homepage.get("html") or ""]

        for url in sampled_urls:
            html = homepage.get("html") if url == homepage_url else self._fetch_html(url)
            if html is None:
                continue

            html_documents.append(html)
            result = self.classifier.classify(url=url, html=html)
            pages_scanned.append(
                {
                    "url": url,
                    "page_types": result.page_types,
                    "matched_reasons": result.matched_reasons,
                }
            )

            if product_url is None and self.classifier.PRODUCT in result.page_types:
                product_url = url
            if category_url is None and self.classifier.CATEGORY in result.page_types:
                category_url = url
            if cart_url is None and self.classifier.CART in result.page_types:
                cart_url = url
            if checkout_url is None and self.classifier.CHECKOUT in result.page_types:
                checkout_url = url

        sitemap_product_url_count = self._estimate_from_sitemap(homepage_url)
        product_count_guess = self.classifier.estimate_product_count(html_documents)
        if product_count_guess is None:
            product_count_guess = sitemap_product_url_count

        product_count_bucket = self.classifier.bucket_product_count(product_count_guess)

        metadata = {
            "homepage_url": homepage_url,
            "sampled_urls": sampled_urls,
            "pages_scanned": pages_scanned,
            "sitemap_product_url_count": sitemap_product_url_count,
        }

        return DomainPageClassification(
            domain=domain,
            product_page_found=product_url is not None,
            category_page_found=category_url is not None,
            cart_page_found=cart_url is not None,
            checkout_page_found=checkout_url is not None,
            sample_product_url=product_url,
            sample_category_url=category_url,
            sample_cart_url=cart_url,
            sample_checkout_url=checkout_url,
            product_count_guess=product_count_guess,
            product_count_bucket=product_count_bucket,
            classification_metadata=metadata,
            classified_at=datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        )

    def _sample_urls(self, homepage_url: str, links: list[str], max_pages: int) -> list[str]:
        priority = [
            "product",
            "products",
            "collection",
            "category",
            "shop",
            "cart",
            "checkout",
        ]

        unique_links = list(dict.fromkeys([homepage_url] + links))
        prioritized = sorted(
            unique_links,
            key=lambda url: (
                0 if url == homepage_url else 1,
                min((url.lower().find(token) for token in priority if token in url.lower()), default=9999),
                len(url),
            ),
        )

        return prioritized[: max(1, max_pages)]

    def _same_domain_links(self, links: list[str], homepage_url: str) -> list[str]:
        homepage_host = self._normalize_host(urlparse(homepage_url).netloc)
        filtered: list[str] = []

        for link in links:
            parsed = urlparse(link)
            if parsed.scheme not in {"http", "https"}:
                continue

            if self._normalize_host(parsed.netloc) != homepage_host:
                continue

            normalized = f"{parsed.scheme}://{parsed.netloc}{parsed.path}".rstrip("/")
            if parsed.query:
                normalized = f"{normalized}?{parsed.query}"
            filtered.append(normalized)

        return list(dict.fromkeys(filtered))

    def _fetch_html(self, url: str) -> str | None:
        try:
            response = requests.get(
                url,
                timeout=8,
                allow_redirects=True,
                headers={
                    "User-Agent": (
                        "Mozilla/5.0 (X11; Linux x86_64) "
                        "AppleWebKit/537.36 (KHTML, like Gecko) "
                        "Chrome/123.0.0.0 Safari/537.36"
                    )
                },
            )
            if response.status_code >= 400:
                return None

            return response.text
        except requests.RequestException:
            return None

    def _estimate_from_sitemap(self, homepage_url: str) -> int | None:
        sitemap_url = f"{homepage_url.rstrip('/')}/sitemap.xml"

        try:
            response = requests.get(sitemap_url, timeout=8)
            if response.status_code >= 400:
                return None

            urls = self.sitemap_parser.extract_urls(response.text)
            product_like = [
                url
                for url in urls
                if any(token in url.lower() for token in ("/product", "/products/", "/item", "sku"))
            ]

            return len(product_like) or None
        except requests.RequestException:
            return None

    @staticmethod
    def _normalize_host(host: str) -> str:
        value = host.strip().lower()
        if value.startswith("www."):
            value = value[4:]
        return value
