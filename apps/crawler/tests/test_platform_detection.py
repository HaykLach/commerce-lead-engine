"""Tests for WhatWeb runner and weighted platform detection engine."""

from __future__ import annotations

import subprocess
from pathlib import Path

import pytest

from lead_crawler.fingerprint.rule_engine import FingerprintInputBundle, FingerprintRuleEngine
from lead_crawler.services.whatweb_runner_service import WhatWebResult, WhatWebRunnerService

FIXTURES_DIR = Path(__file__).parent / "fixtures" / "platforms"


def _fixture(name: str) -> str:
    return (FIXTURES_DIR / f"{name}.html").read_text(encoding="utf-8")


@pytest.mark.parametrize(
    ("fixture_name", "headers", "cookies", "scripts", "styles", "links", "plugins", "expected_platform"),
    [
        (
            "shopify",
            {"x-shopify-stage": "production"},
            {"_shopify_y": "abc"},
            ["https://cdn.shopify.com/s/assets/storefront.js"],
            ["https://cdn.shopify.com/theme.css"],
            ["/products/shoes"],
            {"shopify": {"string": ["Storefront"]}},
            "shopify",
        ),
        (
            "woocommerce",
            {"x-powered-by": "WordPress"},
            {"woocommerce_items_in_cart": "1"},
            ["/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.min.js"],
            [],
            ["/cart"],
            {"wordpress": {}, "woocommerce": {}},
            "woocommerce",
        ),
        (
            "prestashop",
            {},
            {"PrestaShop-123": "token"},
            ["/themes/classic/assets/js/prestashop.js"],
            [],
            ["/category/1-clothes"],
            {"prestashop": {}},
            "prestashop",
        ),
        (
            "shopware",
            {"x-powered-by": "Shopware"},
            {"shopware-redirect": "1"},
            ["/bundles/storefront/storefront/js/shopware.js"],
            [],
            ["/checkout"],
            {"shopware": {}},
            "shopware",
        ),
        (
            "custom_ecommerce",
            {},
            {},
            ["https://cdn.example.com/react.production.min.js"],
            [],
            ["/product/blue-shoes", "/cart", "/checkout"],
            {},
            "custom_ecommerce",
        ),
        (
            "unknown",
            {},
            {},
            [],
            [],
            ["/about", "/contact"],
            {},
            "unknown",
        ),
    ],
)
def test_detect_platform_from_weighted_signals(
    fixture_name: str,
    headers: dict[str, str],
    cookies: dict[str, str],
    scripts: list[str],
    styles: list[str],
    links: list[str],
    plugins: dict[str, dict[str, object]],
    expected_platform: str,
) -> None:
    engine = FingerprintRuleEngine()

    result = engine.detect(
        FingerprintInputBundle(
            html=_fixture(fixture_name),
            headers=headers,
            cookies=cookies,
            script_urls=scripts,
            stylesheet_urls=styles,
            links=links,
            whatweb=WhatWebResult(target_url="https://example.com", plugins=plugins),
        )
    )

    assert result.platform == expected_platform
    assert 0.0 <= result.confidence <= 1.0
    assert isinstance(result.matched_signals, list)


def test_detect_frontend_stack() -> None:
    engine = FingerprintRuleEngine()
    result = engine.detect(
        FingerprintInputBundle(
            html=_fixture("custom_ecommerce"),
            script_urls=["https://cdn.example.com/react.production.min.js"],
            links=["/cart", "/checkout"],
        )
    )

    assert result.platform == "custom_ecommerce"
    assert "react" in result.frontend_stack


def test_whatweb_runner_normalizes_json_output() -> None:
    stdout = (
        '[{"target":"https://example.com","plugins":{"Shopify":{"string":["Storefront"]}}}]\n'
    )

    def _runner(_: list[str]) -> subprocess.CompletedProcess[str]:
        return subprocess.CompletedProcess(args=[], returncode=0, stdout=stdout, stderr="")

    service = WhatWebRunnerService(runner=_runner)
    result = service.scan("https://example.com")

    assert result.error is None
    assert result.target_url == "https://example.com"
    assert "shopify" in result.plugins


def test_whatweb_runner_handles_missing_binary() -> None:
    def _runner(_: list[str]) -> subprocess.CompletedProcess[str]:
        raise FileNotFoundError

    service = WhatWebRunnerService(runner=_runner)
    result = service.scan("https://example.com")

    assert result.plugins == {}
    assert result.error == "whatweb_binary_missing"
