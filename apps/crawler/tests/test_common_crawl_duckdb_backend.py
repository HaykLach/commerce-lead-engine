"""Tests for CommonCrawlDuckDbBackend using CC's real parquet schema."""

from __future__ import annotations

import os
import tempfile
from unittest.mock import MagicMock, patch

import pytest

from lead_crawler.services.common_crawl_duckdb_backend import (
    CommonCrawlDuckDbBackend,
    CommonCrawlDuckDbConfig,
    _CC_HTTPS_BASE,
)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_backend(dataset_path: str, **cfg_kwargs) -> CommonCrawlDuckDbBackend:
    cfg = CommonCrawlDuckDbConfig(dataset_path=dataset_path, **cfg_kwargs)
    return CommonCrawlDuckDbBackend(config=cfg)


def _seed_and_export(rows: list[dict]) -> str:
    """Write rows to a temp parquet file using DuckDB. Returns the file path."""
    import duckdb

    f = tempfile.NamedTemporaryFile(suffix=".parquet", delete=False)
    path = f.name
    f.close()

    with duckdb.connect() as conn:
        conn.execute("""
            CREATE TABLE cc_test (
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
        conn.execute(f"COPY cc_test TO '{path}' (FORMAT PARQUET)")

    return path


def _fetch(rows, patterns, countries=None, limit=10) -> list:
    """Write rows to parquet and run fetch_candidates against it."""
    path = _seed_and_export(rows)
    try:
        backend = _make_backend(path)
        return backend.fetch_candidates(patterns=patterns, limit=limit, countries=countries)
    finally:
        os.unlink(path)


# ---------------------------------------------------------------------------
# list_parquet_urls — unit tests with mocked HTTP
# ---------------------------------------------------------------------------

_SAMPLE_S3_XML = """\
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
  <Contents><Key>cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/part-00000-abc.parquet</Key></Contents>
  <Contents><Key>cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/part-00001-def.parquet</Key></Contents>
  <Contents><Key>cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/part-00002-ghi.parquet</Key></Contents>
</ListBucketResult>"""


class TestListParquetUrls:
    def _mock_response(self, xml_text: str, status: int = 200):
        resp = MagicMock()
        resp.status_code = status
        resp.text = xml_text
        resp.raise_for_status = MagicMock()
        return resp

    def test_returns_https_urls(self):
        with patch("lead_crawler.services.common_crawl_duckdb_backend._requests.get") as mock_get:
            mock_get.return_value = self._mock_response(_SAMPLE_S3_XML)
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13", max_files=3)

        assert len(urls) == 3
        assert all(u.startswith("https://data.commoncrawl.org/") for u in urls)
        assert all(u.endswith(".parquet") for u in urls)

    def test_respects_max_files(self):
        with patch("lead_crawler.services.common_crawl_duckdb_backend._requests.get") as mock_get:
            mock_get.return_value = self._mock_response(_SAMPLE_S3_XML)
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13", max_files=2)

        assert len(urls) == 2

    def test_returns_empty_on_request_failure(self):
        import requests as req_lib
        with patch("lead_crawler.services.common_crawl_duckdb_backend._requests.get") as mock_get:
            mock_get.side_effect = req_lib.RequestException("network error")
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13")

        assert urls == []

    def test_returns_empty_on_bad_xml(self):
        with patch("lead_crawler.services.common_crawl_duckdb_backend._requests.get") as mock_get:
            mock_get.return_value = self._mock_response("<not valid xml><<")
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13")

        assert urls == []

    def test_skips_non_parquet_keys(self):
        xml = """\
<?xml version="1.0" encoding="UTF-8"?>
<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">
  <Contents><Key>cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/part-00000.parquet</Key></Contents>
  <Contents><Key>cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/_SUCCESS</Key></Contents>
  <Contents><Key>cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/part-00001.parquet</Key></Contents>
</ListBucketResult>"""
        with patch("lead_crawler.services.common_crawl_duckdb_backend._requests.get") as mock_get:
            mock_get.return_value = self._mock_response(xml)
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13", max_files=10)

        assert len(urls) == 2
        assert all(u.endswith(".parquet") for u in urls)

    def test_listing_url_contains_crawl_id_and_prefix(self):
        with patch("lead_crawler.services.common_crawl_duckdb_backend._requests.get") as mock_get:
            mock_get.return_value = self._mock_response(_SAMPLE_S3_XML)
            CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13")
            called_url = mock_get.call_args[0][0]

        assert "CC-MAIN-2025-13" in called_url
        assert "subset=warc" in called_url
        assert "commoncrawl.s3.amazonaws.com" in called_url


# ---------------------------------------------------------------------------
# _resolve_dataset — uses list_parquet_urls internally
# ---------------------------------------------------------------------------

class TestResolveDataset:
    def test_uses_explicit_dataset_path(self):
        backend = _make_backend("/local/path.parquet")
        assert backend._resolve_dataset() == "/local/path.parquet"

    def test_lists_files_for_each_crawl(self):
        cfg = CommonCrawlDuckDbConfig(
            crawls=["CC-MAIN-2025-13", "CC-MAIN-2024-51"],
            cc_files_per_crawl=2,
        )
        backend = CommonCrawlDuckDbBackend(config=cfg)

        fake_urls = ["https://data.commoncrawl.org/cc-index/part-0.parquet"]
        with patch.object(backend, "list_parquet_urls", return_value=fake_urls) as mock_list:
            result = backend._resolve_dataset()

        assert mock_list.call_count == 2
        assert result == fake_urls * 2  # 1 file × 2 crawls

    def test_returns_empty_when_listing_fails(self):
        backend = CommonCrawlDuckDbBackend()
        with patch.object(backend, "list_parquet_urls", return_value=[]):
            result = backend._resolve_dataset()
        assert result == []


# ---------------------------------------------------------------------------
# fetch_candidates — integration tests using local parquet
# ---------------------------------------------------------------------------

class TestFetchCandidatesSchema:

    def test_returns_matching_candidates(self):
        rows = [{
            "url": "https://www.fashion-shop.de/products/shoes",
            "url_host_tld": "de", "url_host_name": "fashion-shop.de",
            "fetch_status": 200, "content_mime_detected": "text/html",
        }]
        results = _fetch(rows, patterns=["/products/"], countries=["de"])
        assert len(results) == 1
        assert "fashion-shop.de" in results[0].normalized_domain

    def test_filters_invalid_status(self):
        rows = [{
            "url": "https://www.broken.de/products/item",
            "url_host_tld": "de", "url_host_name": "broken.de",
            "fetch_status": 404, "content_mime_detected": "text/html",
        }]
        assert _fetch(rows, patterns=["/products/"], countries=["de"]) == []

    def test_filters_invalid_mime(self):
        rows = [{
            "url": "https://www.shop.de/products/item.pdf",
            "url_host_tld": "de", "url_host_name": "shop.de",
            "fetch_status": 200, "content_mime_detected": "application/pdf",
        }]
        assert _fetch(rows, patterns=["/products/"], countries=["de"]) == []

    def test_tld_filter_uses_url_host_tld(self):
        rows = [
            {"url": "https://german-shop.de/products/jacket",
             "url_host_tld": "de", "url_host_name": "german-shop.de",
             "fetch_status": 200, "content_mime_detected": "text/html"},
            {"url": "https://dutch-shop.nl/products/jacket",
             "url_host_tld": "nl", "url_host_name": "dutch-shop.nl",
             "fetch_status": 200, "content_mime_detected": "text/html"},
        ]
        results = _fetch(rows, patterns=["/products/"], countries=["de"])
        domains = [r.normalized_domain for r in results]
        assert any("german-shop" in d for d in domains)
        assert not any("dutch-shop" in d for d in domains)

    def test_no_country_filter_returns_all_tlds(self):
        rows = [
            {"url": "https://a.de/products/item", "url_host_tld": "de",
             "url_host_name": "a.de", "fetch_status": 200, "content_mime_detected": "text/html"},
            {"url": "https://b.ae/products/item", "url_host_tld": "ae",
             "url_host_name": "b.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
        ]
        assert len(_fetch(rows, patterns=["/products/"], countries=None)) == 2

    def test_pattern_filter_applied(self):
        rows = [
            {"url": "https://shop.ae/products/sneakers", "url_host_tld": "ae",
             "url_host_name": "shop.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
            {"url": "https://blog.ae/about", "url_host_tld": "ae",
             "url_host_name": "blog.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
        ]
        results = _fetch(rows, patterns=["/products/"], countries=["ae"])
        assert len(results) == 1
        assert "shop.ae" in results[0].normalized_domain

    def test_deduplication_by_domain(self):
        rows = [
            {"url": "https://shop.ae/products/item1", "url_host_tld": "ae",
             "url_host_name": "shop.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
            {"url": "https://shop.ae/products/item2", "url_host_tld": "ae",
             "url_host_name": "shop.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
        ]
        assert len(_fetch(rows, patterns=["/products/"], countries=["ae"])) == 1

    def test_limit_respected(self):
        rows = [
            {"url": f"https://shop{i}.ae/products/item", "url_host_tld": "ae",
             "url_host_name": f"shop{i}.ae", "fetch_status": 200, "content_mime_detected": "text/html"}
            for i in range(10)
        ]
        assert len(_fetch(rows, patterns=["/products/"], countries=["ae"], limit=3)) <= 3

    def test_source_metadata_contains_backend(self):
        rows = [{"url": "https://shop.ae/products/bag", "url_host_tld": "ae",
                 "url_host_name": "shop.ae", "fetch_status": 200, "content_mime_detected": "text/html"}]
        results = _fetch(rows, patterns=["/products/"], countries=["ae"])
        assert results[0].source_metadata["backend"] == "duckdb"

    def test_accepts_redirect_statuses(self):
        rows = [
            {"url": "https://shop.ae/products/bag", "url_host_tld": "ae",
             "url_host_name": "shop.ae", "fetch_status": 301, "content_mime_detected": "text/html"},
            {"url": "https://shop2.ae/products/hat", "url_host_tld": "ae",
             "url_host_name": "shop2.ae", "fetch_status": 302, "content_mime_detected": "text/html"},
        ]
        assert len(_fetch(rows, patterns=["/products/"], countries=["ae"])) == 2

    def test_empty_table_returns_empty(self):
        assert _fetch([], patterns=["/products/"], countries=["ae"]) == []

    def test_empty_dataset_returns_empty_without_error(self):
        backend = CommonCrawlDuckDbBackend()
        with patch.object(backend, "_resolve_dataset", return_value=[]):
            results = backend.fetch_candidates(patterns=["/products/"], limit=10, countries=["de"])
        assert results == []


# ---------------------------------------------------------------------------
# Config tests
# ---------------------------------------------------------------------------

class TestConfig:
    def test_default_config(self):
        b = CommonCrawlDuckDbBackend()
        assert b.config.database == ":memory:"
        assert b.config.dataset_path is None
        assert b.config.cc_files_per_crawl == 3

    def test_config_crawls_override(self):
        cfg = CommonCrawlDuckDbConfig(crawls=["CC-MAIN-2025-13"])
        assert CommonCrawlDuckDbBackend(config=cfg)._get_crawls() == ["CC-MAIN-2025-13"]

    def test_config_crawls_defaults_to_known(self):
        from lead_crawler.services.common_crawl_index_api_backend import KNOWN_CRAWLS
        assert CommonCrawlDuckDbBackend()._get_crawls() == KNOWN_CRAWLS

    def test_missing_duckdb_raises_runtime_error(self, monkeypatch):
        import builtins
        real_import = builtins.__import__

        def patched_import(name, *args, **kwargs):
            if name == "duckdb":
                raise ModuleNotFoundError("No module named 'duckdb'")
            return real_import(name, *args, **kwargs)

        monkeypatch.setattr(builtins, "__import__", patched_import)
        backend = _make_backend("/fake/path.parquet")
        with pytest.raises(RuntimeError, match="duckdb package is required"):
            backend.fetch_candidates(patterns=["/products/"], limit=10)


# ---------------------------------------------------------------------------
# _is_remote tests
# ---------------------------------------------------------------------------

class TestIsRemote:
    def test_https_string(self):
        assert CommonCrawlDuckDbBackend._is_remote("https://data.commoncrawl.org/part-0.parquet")

    def test_s3_string(self):
        assert CommonCrawlDuckDbBackend._is_remote("s3://commoncrawl/cc-index/part-0.parquet")

    def test_local_path(self):
        assert not CommonCrawlDuckDbBackend._is_remote("/data/cc/part-0.parquet")

    def test_https_list(self):
        assert CommonCrawlDuckDbBackend._is_remote([
            "https://data.commoncrawl.org/cc-index/part-0.parquet",
            "https://data.commoncrawl.org/cc-index/part-1.parquet",
        ])

    def test_local_list(self):
        assert not CommonCrawlDuckDbBackend._is_remote(["/local/part-0.parquet"])

    def test_default_resolved_urls_are_remote(self):
        """After listing, resolved URLs are HTTPS — _is_remote must be True."""
        fake_urls = [
            "https://data.commoncrawl.org/cc-index/table/cc-main/warc/"
            "crawl=CC-MAIN-2025-13/subset=warc/part-00000-abc.parquet"
        ]
        backend = CommonCrawlDuckDbBackend()
        with patch.object(backend, "list_parquet_urls", return_value=fake_urls):
            dataset = backend._resolve_dataset()
        assert CommonCrawlDuckDbBackend._is_remote(dataset)
