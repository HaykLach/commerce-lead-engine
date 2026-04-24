"""Tests for CommonCrawlDuckDbBackend using CC's real parquet schema."""

from __future__ import annotations

import pytest

from lead_crawler.services.common_crawl_duckdb_backend import (
    CommonCrawlDuckDbBackend,
    CommonCrawlDuckDbConfig,
)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_backend(dataset_path: str, extra_config: dict | None = None) -> CommonCrawlDuckDbBackend:
    """Return a backend pointing at a local parquet or DuckDB in-memory table."""
    cfg = CommonCrawlDuckDbConfig(
        dataset_path=dataset_path,
        **(extra_config or {}),
    )
    return CommonCrawlDuckDbBackend(config=cfg)


def _seed_table(conn, rows: list[dict]) -> None:
    """Create and populate a CC-schema parquet-compatible in-memory table."""
    conn.execute("""
        CREATE TABLE IF NOT EXISTS cc_test (
            url                   VARCHAR,
            url_host_tld          VARCHAR,
            url_host_name         VARCHAR,
            fetch_status          INTEGER,
            content_mime_detected VARCHAR,
            crawl                 VARCHAR,
            subset                VARCHAR
        )
    """)
    for row in rows:
        conn.execute(
            "INSERT INTO cc_test VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                row.get("url"),
                row.get("url_host_tld"),
                row.get("url_host_name"),
                row.get("fetch_status", 200),
                row.get("content_mime_detected", "text/html"),
                row.get("crawl", "CC-MAIN-2025-13"),
                row.get("subset", "warc"),
            ],
        )


# ---------------------------------------------------------------------------
# Unit tests for cc_parquet_glob helper
# ---------------------------------------------------------------------------

class TestCcParquetGlob:
    def test_single_crawl(self):
        urls = CommonCrawlDuckDbBackend.cc_s3_globs(["CC-MAIN-2025-13"])
        assert len(urls) == 1
        assert "CC-MAIN-2025-13" in urls[0]
        assert urls[0].startswith("s3://commoncrawl")
        assert urls[0].endswith(".parquet")

    def test_multiple_crawls(self):
        urls = CommonCrawlDuckDbBackend.cc_s3_globs(["CC-MAIN-2025-13", "CC-MAIN-2024-51"])
        assert len(urls) == 2

    def test_defaults_to_known_crawls(self):
        from lead_crawler.services.common_crawl_index_api_backend import KNOWN_CRAWLS
        urls = CommonCrawlDuckDbBackend.cc_s3_globs(None)
        assert len(urls) == len(KNOWN_CRAWLS)

    def test_backward_compat_alias(self):
        # cc_parquet_glob is kept as a backward-compat alias
        urls = CommonCrawlDuckDbBackend.cc_parquet_glob(["CC-MAIN-2025-13"])
        assert urls == CommonCrawlDuckDbBackend.cc_s3_globs(["CC-MAIN-2025-13"])


# ---------------------------------------------------------------------------
# Integration tests using DuckDB in-memory table (CC schema)
# ---------------------------------------------------------------------------

class TestFetchCandidatesSchema:
    """Verify the backend works with CC's real parquet column names."""

    def _fetch_via_table(self, rows, patterns, countries=None, limit=10):
        """
        Work around: we can't point `dataset_path` at an in-memory table.
        Instead we use a persistent DuckDB file and query it.
        """
        import duckdb
        import tempfile, os

        with tempfile.NamedTemporaryFile(suffix=".parquet", delete=False) as f:
            parquet_path = f.name

        try:
            with duckdb.connect() as conn:
                _seed_table(conn, rows)
                conn.execute(f"COPY cc_test TO '{parquet_path}' (FORMAT PARQUET)")

            backend = _make_backend(parquet_path)
            return backend.fetch_candidates(
                patterns=patterns,
                limit=limit,
                countries=countries,
            )
        finally:
            os.unlink(parquet_path)

    def test_returns_matching_candidates(self):
        rows = [
            {
                "url": "https://www.fashion-shop.de/products/shoes",
                "url_host_tld": "de",
                "url_host_name": "fashion-shop.de",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["de"])
        assert len(results) == 1
        assert "fashion-shop.de" in results[0].normalized_domain

    def test_filters_invalid_status(self):
        rows = [
            {
                "url": "https://www.broken.de/products/item",
                "url_host_tld": "de",
                "url_host_name": "broken.de",
                "fetch_status": 404,         # invalid
                "content_mime_detected": "text/html",
            },
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["de"])
        assert results == []

    def test_filters_invalid_mime(self):
        rows = [
            {
                "url": "https://www.shop.de/products/item.pdf",
                "url_host_tld": "de",
                "url_host_name": "shop.de",
                "fetch_status": 200,
                "content_mime_detected": "application/pdf",   # not HTML
            },
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["de"])
        assert results == []

    def test_tld_filter_uses_url_host_tld(self):
        rows = [
            {
                "url": "https://www.german-shop.de/products/jacket",
                "url_host_tld": "de",
                "url_host_name": "german-shop.de",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
            {
                "url": "https://www.dutch-shop.nl/products/jacket",
                "url_host_tld": "nl",
                "url_host_name": "dutch-shop.nl",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["de"])
        domains = [r.normalized_domain for r in results]
        assert any("german-shop" in d for d in domains)
        assert not any("dutch-shop" in d for d in domains)

    def test_no_country_filter_returns_all_tlds(self):
        rows = [
            {
                "url": "https://www.a.de/products/item",
                "url_host_tld": "de",
                "url_host_name": "a.de",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
            {
                "url": "https://www.b.ae/products/item",
                "url_host_tld": "ae",
                "url_host_name": "b.ae",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
        ]
        # countries=None → no TLD filter
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=None)
        assert len(results) == 2

    def test_pattern_filter_applied(self):
        rows = [
            {
                "url": "https://www.shop.ae/products/sneakers",
                "url_host_tld": "ae",
                "url_host_name": "shop.ae",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
            {
                "url": "https://www.blog.ae/about",
                "url_host_tld": "ae",
                "url_host_name": "blog.ae",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["ae"])
        assert len(results) == 1
        assert "shop.ae" in results[0].normalized_domain

    def test_deduplication_by_domain(self):
        rows = [
            {
                "url": "https://www.shop.ae/products/item1",
                "url_host_tld": "ae",
                "url_host_name": "shop.ae",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
            {
                "url": "https://www.shop.ae/products/item2",
                "url_host_tld": "ae",
                "url_host_name": "shop.ae",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["ae"])
        assert len(results) == 1

    def test_limit_respected(self):
        rows = [
            {
                "url": f"https://www.shop{i}.ae/products/item",
                "url_host_tld": "ae",
                "url_host_name": f"shop{i}.ae",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            }
            for i in range(10)
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["ae"], limit=3)
        assert len(results) <= 3

    def test_source_metadata_contains_backend(self):
        rows = [
            {
                "url": "https://www.shop.ae/products/bag",
                "url_host_tld": "ae",
                "url_host_name": "shop.ae",
                "fetch_status": 200,
                "content_mime_detected": "text/html",
            },
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["ae"])
        assert results[0].source_metadata["backend"] == "duckdb"

    def test_accepts_redirect_statuses(self):
        rows = [
            {
                "url": "https://www.shop.ae/products/bag",
                "url_host_tld": "ae",
                "url_host_name": "shop.ae",
                "fetch_status": 301,
                "content_mime_detected": "text/html",
            },
            {
                "url": "https://www.shop2.ae/products/hat",
                "url_host_tld": "ae",
                "url_host_name": "shop2.ae",
                "fetch_status": 302,
                "content_mime_detected": "text/html",
            },
        ]
        results = self._fetch_via_table(rows, patterns=["/products/"], countries=["ae"])
        assert len(results) == 2

    def test_empty_table_returns_empty(self):
        results = self._fetch_via_table([], patterns=["/products/"], countries=["ae"])
        assert results == []


# ---------------------------------------------------------------------------
# Config tests
# ---------------------------------------------------------------------------

class TestConfig:
    def test_default_config(self):
        backend = CommonCrawlDuckDbBackend()
        assert backend.config.database == ":memory:"
        assert backend.config.dataset_path is None

    def test_config_crawls_override(self):
        cfg = CommonCrawlDuckDbConfig(crawls=["CC-MAIN-2025-13"])
        backend = CommonCrawlDuckDbBackend(config=cfg)
        assert backend._get_crawls() == ["CC-MAIN-2025-13"]

    def test_config_crawls_defaults_to_known(self):
        from lead_crawler.services.common_crawl_index_api_backend import KNOWN_CRAWLS
        backend = CommonCrawlDuckDbBackend()
        assert backend._get_crawls() == KNOWN_CRAWLS

    def test_missing_duckdb_raises_runtime_error(self, monkeypatch):
        import builtins
        real_import = builtins.__import__

        def patched_import(name, *args, **kwargs):
            if name == "duckdb":
                raise ModuleNotFoundError("No module named 'duckdb'")
            return real_import(name, *args, **kwargs)

        monkeypatch.setattr(builtins, "__import__", patched_import)
        backend = CommonCrawlDuckDbBackend()
        with pytest.raises(RuntimeError, match="duckdb package is required"):
            backend.fetch_candidates(patterns=["/products/"], limit=10)


# ---------------------------------------------------------------------------
# is_https detection tests
# ---------------------------------------------------------------------------

class TestIsRemote:
    def test_s3_string(self):
        assert CommonCrawlDuckDbBackend._is_remote("s3://commoncrawl/cc-index/part-0.parquet")

    def test_https_string(self):
        assert CommonCrawlDuckDbBackend._is_remote("https://example.com/file.parquet")

    def test_local_path_not_remote(self):
        assert not CommonCrawlDuckDbBackend._is_remote("/data/cc/part-0.parquet")

    def test_s3_list(self):
        assert CommonCrawlDuckDbBackend._is_remote([
            "s3://commoncrawl/cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/*.parquet"
        ])

    def test_local_list(self):
        assert not CommonCrawlDuckDbBackend._is_remote(["/local/path/part-0.parquet"])

    def test_default_dataset_is_s3(self):
        backend = CommonCrawlDuckDbBackend()
        dataset = backend._resolve_dataset()
        assert CommonCrawlDuckDbBackend._is_remote(dataset)
        assert not CommonCrawlDuckDbBackend._is_https(dataset)  # S3, not HTTPS
