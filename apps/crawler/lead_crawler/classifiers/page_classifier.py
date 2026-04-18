"""Heuristic page classification for ecommerce crawls."""

from __future__ import annotations

from dataclasses import dataclass, field
from html import unescape
from re import DOTALL, IGNORECASE, finditer, search, sub
from urllib.parse import parse_qs, urlparse


@dataclass(slots=True)
class PageClassificationResult:
    """Structured page classification output with matched reasons."""

    url: str
    is_homepage: bool
    page_types: list[str]
    matched_reasons: dict[str, list[str]] = field(default_factory=dict)


class PageClassifier:
    """Classifies ecommerce pages into project-specific page types."""

    PRODUCT = "product_page_found"
    CATEGORY = "category_page_found"
    CART = "cart_page_found"
    CHECKOUT = "checkout_page_found"
    CONTACT = "contact_page_found"

    def classify(self, url: str, html: str) -> PageClassificationResult:
        """Classify a page and return all matching page categories with reasons."""
        normalized_html = html.lower()
        normalized_text = self._extract_text(html)
        parsed = urlparse(url)
        path = parsed.path.lower().strip("/")
        query = parse_qs(parsed.query.lower())

        is_homepage = path in {"", "index", "index.html", "home"}

        matched_reasons: dict[str, list[str]] = {}

        product_reasons = self._product_reasons(path, query, normalized_html, normalized_text)
        if product_reasons:
            matched_reasons[self.PRODUCT] = product_reasons

        category_reasons = self._category_reasons(path, query, normalized_html, normalized_text)
        if category_reasons:
            matched_reasons[self.CATEGORY] = category_reasons

        cart_reasons = self._cart_reasons(path, normalized_html, normalized_text)
        if cart_reasons:
            matched_reasons[self.CART] = cart_reasons

        checkout_reasons = self._checkout_reasons(path, normalized_html, normalized_text)
        if checkout_reasons:
            matched_reasons[self.CHECKOUT] = checkout_reasons

        contact_reasons = self._contact_reasons(path, normalized_html, normalized_text)
        if contact_reasons:
            matched_reasons[self.CONTACT] = contact_reasons

        return PageClassificationResult(
            url=url,
            is_homepage=is_homepage,
            page_types=list(matched_reasons.keys()),
            matched_reasons=matched_reasons,
        )

    def _product_reasons(self, path: str, query: dict[str, list[str]], html: str, text: str) -> list[str]:
        reasons: list[str] = []

        if any(segment in path for segment in ("product", "products", "item", "sku", "p/", "/p")):
            reasons.append("url_path_contains_product_identifier")

        if any(param in query for param in ("variant", "sku", "product")):
            reasons.append("url_query_contains_product_identifier")

        if search(r'"@type"\s*:\s*"product"', html):
            reasons.append("product_schema_detected")

        if search(r'itemprop\s*=\s*["\'](?:sku|price)["\']', html):
            reasons.append("sku_or_price_schema_detected")

        if search(r"\b(?:add to cart|add to bag|buy now)\b", text):
            reasons.append("purchase_cta_detected")

        if search(r"(?:\$|€|£)\s?\d", text):
            reasons.append("price_signal_detected")

        if search(r"\b(?:select size|choose size|select color|choose color|variant)\b", text):
            reasons.append("variant_selector_signal_detected")

        return reasons if len(reasons) >= 2 else []

    def _category_reasons(self, path: str, query: dict[str, list[str]], html: str, text: str) -> list[str]:
        reasons: list[str] = []

        if any(segment in path for segment in ("collections", "category", "categories", "shop", "catalog")):
            reasons.append("url_path_contains_category_identifier")

        if any(param in query for param in ("page", "sort", "filter")):
            reasons.append("query_contains_listing_controls")

        if self._count_occurrences(html, ("product-card", "product-grid", "collection-grid", "grid-item", "product-list")) >= 2:
            reasons.append("product_grid_pattern_detected")

        if self._count_occurrences(text, ("filter", "sort by", "price range", "showing", "results")) >= 2:
            reasons.append("faceted_filters_or_listing_copy_detected")

        if search(r"\b(page\s+\d+|next\s*page|pagination|showing\s+\d+\s*[–-]\s*\d+\s+of\s+\d+)\b", text):
            reasons.append("pagination_detected")

        return reasons if len(reasons) >= 2 else []

    def _cart_reasons(self, path: str, html: str, text: str) -> list[str]:
        reasons: list[str] = []

        if any(segment in path for segment in ("cart", "basket", "bag")):
            reasons.append("url_path_contains_cart_identifier")

        if self._count_occurrences(text, ("quantity", "subtotal", "remove", "line item", "update cart", "total")) >= 2:
            reasons.append("line_items_and_subtotal_signals_detected")

        if search(r'class\s*=\s*["\'][^"\']*(cart-item|line-item|mini-cart)[^"\']*["\']', html):
            reasons.append("cart_item_markup_detected")

        return reasons if len(reasons) >= 2 else []

    def _checkout_reasons(self, path: str, html: str, text: str) -> list[str]:
        reasons: list[str] = []

        if "checkout" in path or any(part in path for part in ("shipping", "payment")):
            reasons.append("url_path_contains_checkout_identifier")

        form_signals = self._count_occurrences(
            text,
            (
                "shipping",
                "payment",
                "billing",
                "address",
                "card number",
                "place order",
                "delivery",
            ),
        )
        if form_signals >= 3:
            reasons.append("shipping_payment_address_form_signals_detected")

        if search(r'name\s*=\s*["\'](?:address|postal|zip|card(?:number)?|payment)["\']', html):
            reasons.append("checkout_form_fields_detected")

        if search(r"\b(?:step\s*\d|shipping\s*>\s*payment|checkout\s+step)\b", text):
            reasons.append("checkout_stepper_detected")

        return reasons if len(reasons) >= 2 else []

    def _contact_reasons(self, path: str, html: str, text: str) -> list[str]:
        reasons: list[str] = []

        if any(segment in path for segment in ("contact", "support", "help")):
            reasons.append("url_path_contains_contact_identifier")

        if "mailto:" in html or search(r"\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b", text):
            reasons.append("contact_email_detected")

        if search(r"\+?\d[\d\s().-]{7,}\d", text):
            reasons.append("contact_phone_detected")

        if "contact form" in text or "get in touch" in text or "message us" in text:
            reasons.append("contact_form_copy_detected")

        if "address" in text:
            reasons.append("contact_address_detected")

        return reasons if len(reasons) >= 2 else []

    def estimate_product_count(self, html_documents: list[str]) -> int | None:
        """Estimate product count from listing copy and pagination hints."""
        for html in html_documents:
            explicit = self._extract_explicit_listing_total(html)
            if explicit is not None:
                return explicit

        pagination_max = 0
        per_page_guess = 0
        for html in html_documents:
            text = self._extract_text(html)
            page_numbers = [int(match.group(1)) for match in finditer(r"\bpage\s*(\d{1,4})\b", text, flags=IGNORECASE)]
            if page_numbers:
                pagination_max = max(pagination_max, max(page_numbers))

            per_page_guess = max(per_page_guess, self._estimate_products_per_page(html))

        if pagination_max >= 2 and per_page_guess >= 1:
            return pagination_max * per_page_guess

        return None

    @staticmethod
    def _estimate_products_per_page(html: str) -> int:
        normalized = html.lower()
        class_hits = len(list(finditer(r"product-card|grid-item|product-item|collection-product", normalized)))
        if class_hits > 0:
            return min(class_hits, 120)

        text = PageClassifier._extract_text(html)
        fallback_hits = len(list(finditer(r"\badd to cart\b", text)))
        return min(fallback_hits, 120)

    @staticmethod
    def _extract_explicit_listing_total(html: str) -> int | None:
        text = PageClassifier._extract_text(html)

        patterns = [
            r"showing\s+\d+\s*[–-]\s*\d+\s+of\s+(\d{1,7})",
            r"\bof\s+(\d{1,7})\s+products\b",
            r"\b(\d{1,7})\s+products\b",
            r"\b(\d{1,7})\s+items\b",
        ]

        for pattern in patterns:
            match = search(pattern, text, flags=IGNORECASE)
            if not match:
                continue

            total = int(match.group(1))
            if total > 0:
                return total

        return None

    @staticmethod
    def bucket_product_count(product_count_guess: int | None) -> str | None:
        if product_count_guess is None or product_count_guess <= 0:
            return None

        if product_count_guess <= 50:
            return "1-50"

        if product_count_guess <= 200:
            return "51-200"

        if product_count_guess <= 1000:
            return "201-1000"

        return "1000+"

    @staticmethod
    def _extract_text(html: str) -> str:
        text = sub(r"<script[\s\S]*?</script>", " ", html, flags=DOTALL)
        text = sub(r"<style[\s\S]*?</style>", " ", text, flags=DOTALL)
        text = sub(r"<[^>]+>", " ", text)
        text = unescape(text)
        text = sub(r"\s+", " ", text)
        return text.lower().strip()

    @staticmethod
    def _count_occurrences(content: str, keywords: tuple[str, ...]) -> int:
        return sum(1 for keyword in keywords if keyword in content)
