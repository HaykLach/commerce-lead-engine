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


def test_aggregate_rows_merges_domain_scores():
    importer = CommonCrawlImporter()

    rows = [
        ("https://store.example.de/products/sku-1", "de", "store.example.de", "CC-MAIN-2025-13", "warc"),
        ("https://store.example.de/cart", "de", "store.example.de", "CC-MAIN-2025-13", "warc"),
        ("https://store.example.de/checkout", "de", "store.example.de", "CC-MAIN-2025-13", "warc"),
    ]
    aggregated, stats = importer._aggregate_rows(
        rows,
        fallback_crawl_id="CC-MAIN-2025-13",
        countries=[],
        minimum_import_score=0.05,
    )

    assert "store.example.de" in aggregated
    entry = aggregated["store.example.de"]
    assert entry["ecommerce_score"] > 0.5
    assert "product" in entry["matched_patterns"]
    assert "cart" in entry["matched_patterns"]
    assert stats.rows_read_before_filters == 3
    assert stats.rows_accepted_after_country_filter == 3


def test_aggregate_rows_filters_country_by_normalized_domain_tld_and_applies_minimum_score():
    importer = CommonCrawlImporter()
    rows = [
        ("https://www.shop.example.de/about", "", "www.shop.example.de", "CC-MAIN-2025-13", "warc"),
        ("https://store.example.fr/products/1", "", "store.example.fr", "CC-MAIN-2025-13", "warc"),
        ("notaurl", "", "", "CC-MAIN-2025-13", "warc"),
    ]

    aggregated, stats = importer._aggregate_rows(
        rows,
        fallback_crawl_id="CC-MAIN-2025-13",
        countries=["de"],
        minimum_import_score=0.05,
    )

    assert "shop.example.de" in aggregated
    assert aggregated["shop.example.de"]["ecommerce_score"] == 0.05
    assert "store.example.fr" not in aggregated
    assert stats.rows_skipped_country_filter == 1
    assert stats.rows_skipped_invalid_domain == 1
