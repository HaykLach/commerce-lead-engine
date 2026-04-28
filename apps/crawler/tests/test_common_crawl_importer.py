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


def test_detect_country_signals_de_rejects_language_only_signal():
    importer = CommonCrawlImporter()
    matched, country_signals, language_signals = importer.detect_country_signals(
        url="https://www.shop.example.com/de/produkte/schuhe",
        domain="shop.example.com",
        country="de",
    )
    assert matched is False
    assert country_signals == []
    assert "url:/de/" in language_signals
    assert "url:/produkte" in language_signals


def test_detect_country_signals_de_accepts_strong_tld():
    importer = CommonCrawlImporter()
    matched, country_signals, language_signals = importer.detect_country_signals(
        url="https://example.de/product",
        domain="example.de",
        country="de",
    )

    assert matched is True
    assert "tld:de" in country_signals
    assert language_signals == []


def test_detect_country_signals_de_accepts_regional_tld_with_language_path():
    importer = CommonCrawlImporter()
    matched, country_signals, language_signals = importer.detect_country_signals(
        url="https://startupcity.hamburg/de/page",
        domain="startupcity.hamburg",
        country="de",
    )

    assert matched is True
    assert "tld:hamburg" in country_signals
    assert "url:/de/" in language_signals


def test_detect_country_signals_de_accepts_strong_url_patterns():
    importer = CommonCrawlImporter()
    matched, country_signals, _language_signals = importer.detect_country_signals(
        url="https://example.com/de-de/products",
        domain="example.com",
        country="de",
    )
    assert matched is True
    assert "url:/de-de/" in country_signals

    matched, country_signals, _language_signals = importer.detect_country_signals(
        url="https://example.com/deutschland/products",
        domain="example.com",
        country="de",
    )
    assert matched is True
    assert "url:/deutschland" in country_signals


def test_detect_country_signals_de_rejects_language_only_domains():
    importer = CommonCrawlImporter()

    matched, country_signals, language_signals = importer.detect_country_signals(
        url="https://gsis.edu.hk/de/about",
        domain="gsis.edu.hk",
        country="de",
    )
    assert matched is False
    assert country_signals == []
    assert "url:/de/" in language_signals

    matched, country_signals, language_signals = importer.detect_country_signals(
        url="https://zouridakis.gr/de/blog",
        domain="zouridakis.gr",
        country="de",
    )
    assert matched is False
    assert country_signals == []
    assert "url:/de/" in language_signals

    matched, country_signals, language_signals = importer.detect_country_signals(
        url="https://example.com/produkte/item",
        domain="example.com",
        country="de",
    )
    assert matched is False
    assert country_signals == []
    assert "url:/produkte" in language_signals


def test_detect_country_signals_us_requires_strong_country_signal():
    importer = CommonCrawlImporter()
    matched, country_signals, language_signals = importer.detect_country_signals(
        url="https://example.com/products",
        domain="example.com",
        country="us",
    )
    assert matched is False
    assert country_signals == []
    assert "url:/products" in language_signals

    matched, country_signals, _language_signals = importer.detect_country_signals(
        url="https://example.us/products",
        domain="example.us",
        country="us",
    )
    assert matched is True
    assert "tld:us" in country_signals

    matched, country_signals, _language_signals = importer.detect_country_signals(
        url="https://example.com/en-us/products",
        domain="example.com",
        country="us",
    )
    assert matched is True
    assert "url:/en-us/" in country_signals


def test_detect_country_signals_returns_false_for_unsupported_country():
    importer = CommonCrawlImporter()
    matched, country_signals, language_signals = importer.detect_country_signals(
        url="https://www.example.com/products",
        domain="example.com",
        country="ae",
    )
    assert matched is False
    assert country_signals == []
    assert language_signals == []


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
