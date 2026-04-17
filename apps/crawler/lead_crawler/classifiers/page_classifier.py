"""Heuristic page classification for ecommerce crawls."""

from __future__ import annotations

from dataclasses import dataclass, field
from html import unescape
from re import DOTALL, search, sub
from urllib.parse import urlparse


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
        path = urlparse(url).path.lower().strip("/")

        is_homepage = path in {"", "index", "index.html", "home"}

        matched_reasons: dict[str, list[str]] = {}

        product_reasons = self._product_reasons(path, normalized_html, normalized_text)
        if product_reasons:
            matched_reasons[self.PRODUCT] = product_reasons

        category_reasons = self._category_reasons(path, normalized_html, normalized_text)
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

    def _product_reasons(self, path: str, html: str, text: str) -> list[str]:
        reasons: list[str] = []
        if any(segment in path for segment in ("product", "products", "item", "sku")):
            reasons.append("url_path_contains_product_identifier")
        if search(r'itemprop\s*=\s*["\']sku["\']', html) or '"sku"' in html:
            reasons.append("sku_or_schema_detected")
        if search(r'itemprop\s*=\s*["\']price["\']', html) or '"price"' in html:
            reasons.append("price_schema_detected")

        signal_count = 0
        if search(r"(?:\$|€|£)\s?\d", text):
            signal_count += 1
        if "add to cart" in text or "buy now" in text:
            signal_count += 1
        if "sku" in text:
            signal_count += 1

        if signal_count >= 2:
            reasons.append("price_add_to_cart_and_sku_signals_detected")

        return reasons

    def _category_reasons(self, path: str, html: str, text: str) -> list[str]:
        reasons: list[str] = []

        if any(segment in path for segment in ("collections", "category", "categories", "shop")):
            reasons.append("url_path_contains_category_identifier")

        if self._count_occurrences(html, ("product-card", "product-grid", "collection-grid", "grid-item")) >= 2:
            reasons.append("product_grid_pattern_detected")

        if self._count_occurrences(text, ("filter", "sort by", "price range", "size")) >= 2:
            reasons.append("faceted_filters_detected")

        if search(r"\b(page\s+\d+|next\s*page|pagination)\b", text):
            reasons.append("pagination_detected")

        if len(reasons) >= 2:
            return reasons
        return []

    def _cart_reasons(self, path: str, html: str, text: str) -> list[str]:
        reasons: list[str] = []

        if any(segment in path for segment in ("cart", "basket", "bag")):
            reasons.append("url_path_contains_cart_identifier")

        if self._count_occurrences(text, ("quantity", "subtotal", "remove", "line item", "update cart")) >= 2:
            reasons.append("line_items_and_subtotal_signals_detected")

        if search(r'class\s*=\s*["\'][^"\']*(cart-item|line-item)[^"\']*["\']', html):
            reasons.append("cart_item_markup_detected")

        if len(reasons) >= 2:
            return reasons
        return []

    def _checkout_reasons(self, path: str, html: str, text: str) -> list[str]:
        reasons: list[str] = []

        if "checkout" in path:
            reasons.append("url_path_contains_checkout_identifier")

        form_signals = self._count_occurrences(
            text,
            ("shipping", "payment", "billing", "address", "card number", "place order"),
        )
        if form_signals >= 3:
            reasons.append("shipping_payment_address_form_signals_detected")

        if search(r'name\s*=\s*["\'](address|postal|zip|card(number)?)', html):
            reasons.append("checkout_form_fields_detected")

        if len(reasons) >= 2:
            return reasons
        return []

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

        if len(reasons) >= 2:
            return reasons
        return []

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
