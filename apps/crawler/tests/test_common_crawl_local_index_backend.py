import sys
from unittest.mock import Mock

sys.modules.setdefault("bs4", Mock())
sys.modules.setdefault("requests", Mock())

from lead_crawler.services.common_crawl_local_index_backend import (
    CommonCrawlLocalIndexBackend,
    CommonCrawlLocalIndexConfig,
)


def test_local_index_backend_maps_mysql_rows(monkeypatch):
    class FakeCursor:
        def __init__(self):
            self.executed = None

        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            return False

        def execute(self, sql, params):
            self.executed = (sql, params)

        def fetchall(self):
            return [
                ("shop-one.de", "de", 0.9, '["product","cart"]', "https://shop-one.de/products/sku", "CC-MAIN-2025-13", None),
                ("shop-two.nl", "nl", 0.4, None, None, "CC-MAIN-2025-13", None),
            ]

    class FakeConnection:
        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            return False

        def cursor(self):
            return FakeCursor()

    backend = CommonCrawlLocalIndexBackend(
        CommonCrawlLocalIndexConfig(min_sme_score=0.3)
    )
    monkeypatch.setattr(backend, "_mysql_connection", lambda: FakeConnection())

    results = backend.fetch_candidates(patterns=["/products/"], limit=2, countries=["de", "nl"])

    assert len(results) == 2
    assert results[0].candidate_url == "https://shop-one.de/products/sku"
    assert results[0].matched_pattern == "product"
    assert results[1].candidate_url == "https://shop-two.nl"
