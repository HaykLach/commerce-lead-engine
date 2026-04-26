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
    aggregated = importer._aggregate_rows(rows, fallback_crawl_id="CC-MAIN-2025-13")

    assert "store.example.de" in aggregated
    entry = aggregated["store.example.de"]
    assert entry["ecommerce_score"] > 0.5
    assert "product" in entry["matched_patterns"]
    assert "cart" in entry["matched_patterns"]
