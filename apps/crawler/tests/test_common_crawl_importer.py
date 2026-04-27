import sys
from unittest.mock import Mock

sys.modules.setdefault("bs4", Mock())
sys.modules.setdefault("requests", Mock())

from lead_crawler.services.common_crawl_importer import CommonCrawlImporter


def test_pattern_match_score_accumulates_and_clamps():
    score, matched = CommonCrawlImporter.pattern_match_score(
        "https://shop.example.de/products/item/cart/checkout/store/category"
    )

    assert score == 1.0
    assert "product" in matched
    assert "shop" in matched
    assert "checkout" in matched


def test_detect_country_signals_matches_tld_and_url_patterns():
    importer = CommonCrawlImporter()
    matched, signals = importer.detect_country_signals(
        url="https://www.shop.example.com/de/produkte/schuhe",
        domain="shop.example.com",
        country="de",
    )
    assert matched is True
    assert "url:/de/" in signals
    assert "url:/produkte" in signals


def test_detect_country_signals_returns_false_for_unsupported_country():
    importer = CommonCrawlImporter()
    matched, signals = importer.detect_country_signals(
        url="https://www.example.com/products",
        domain="example.com",
        country="ae",
    )
    assert matched is False
    assert signals == []


def test_run_import_requires_exactly_one_supported_country():
    importer = CommonCrawlImporter()

    try:
        importer.run_import({"countries": []})
    except ValueError as exc:
        assert "Exactly one country must be provided" in str(exc)
    else:
        raise AssertionError("Expected ValueError for empty countries.")

    try:
        importer.run_import({"countries": ["de", "nl"]})
    except ValueError as exc:
        assert "Exactly one country must be provided" in str(exc)
    else:
        raise AssertionError("Expected ValueError for multiple countries.")

    try:
        importer.run_import({"countries": ["ae"]})
    except ValueError as exc:
        assert "Unsupported country" in str(exc)
    else:
        raise AssertionError("Expected ValueError for unsupported country.")
