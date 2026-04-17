"""Tests for ecommerce page classification heuristics."""

from __future__ import annotations

from pathlib import Path

import pytest

from lead_crawler.classifiers.page_classifier import PageClassifier

FIXTURES_DIR = Path(__file__).parent / "fixtures" / "page_classification"


def _fixture(name: str) -> str:
    return (FIXTURES_DIR / f"{name}.html").read_text(encoding="utf-8")


@pytest.mark.parametrize(
    ("fixture_name", "url", "expected_type", "expected_reason"),
    [
        ("product", "https://example.com/products/running-shoe", "product_page_found", "sku_or_schema_detected"),
        ("category", "https://example.com/collections/running-shoes", "category_page_found", "product_grid_pattern_detected"),
        ("cart", "https://example.com/cart", "cart_page_found", "line_items_and_subtotal_signals_detected"),
        ("checkout", "https://example.com/checkout", "checkout_page_found", "shipping_payment_address_form_signals_detected"),
        ("contact", "https://example.com/contact", "contact_page_found", "contact_email_detected"),
    ],
)
def test_classifier_detects_page_types_and_reasons(
    fixture_name: str,
    url: str,
    expected_type: str,
    expected_reason: str,
) -> None:
    classifier = PageClassifier()

    result = classifier.classify(url=url, html=_fixture(fixture_name))

    assert expected_type in result.page_types
    assert expected_reason in result.matched_reasons[expected_type]


def test_classifier_detects_homepage() -> None:
    classifier = PageClassifier()

    result = classifier.classify(url="https://example.com/", html=_fixture("homepage"))

    assert result.is_homepage is True
    assert result.url == "https://example.com/"
    assert isinstance(result.matched_reasons, dict)
