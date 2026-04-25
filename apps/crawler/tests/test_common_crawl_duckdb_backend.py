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
# Fake boto3 helpers
# ---------------------------------------------------------------------------

def _make_s3_response(keys: list[str]) -> dict:
    """Build a fake s3.list_objects_v2 response dict."""
    return {
        "Contents": [{"Key": k} for k in keys],
        "ResponseMetadata": {},
    }


def _s3_keys_for(crawl_id: str, n: int = 3) -> list[str]:
    prefix = f"cc-index/table/cc-main/warc/crawl={crawl_id}/subset=warc/"
    return [f"{prefix}part-{i:05d}-abc.parquet" for i in range(n)]


# ---------------------------------------------------------------------------
# list_parquet_urls — unit tests (boto3 mocked)
# ---------------------------------------------------------------------------

def _patch_boto3(fake_s3):
    """Patch the module-level boto3 reference in the backend module."""
    import lead_crawler.services.common_crawl_duckdb_backend as mod
    mock_boto3 = MagicMock()
    mock_boto3.client.return_value = fake_s3
    return patch.object(mod, "boto3", mock_boto3)


class TestListParquetUrls:

    def test_returns_https_urls(self):
        keys = _s3_keys_for("CC-MAIN-2025-13", 3)
        fake_s3 = MagicMock()
        fake_s3.list_objects_v2.return_value = _make_s3_response(keys)
        with _patch_boto3(fake_s3):
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13", max_files=3)

        assert len(urls) == 3
        assert all(u.startswith(_CC_HTTPS_BASE) for u in urls)
        assert all(u.endswith(".parquet") for u in urls)

    def test_respects_max_files(self):
        keys = _s3_keys_for("CC-MAIN-2025-13", 10)
        fake_s3 = MagicMock()
        fake_s3.list_objects_v2.return_value = _make_s3_response(keys)
        with _patch_boto3(fake_s3):
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13", max_files=2)

        assert len(urls) == 2

    def test_skips_non_parquet_keys(self):
        keys = [
            "cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/part-00000.parquet",
            "cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/_SUCCESS",
            "cc-index/table/cc-main/warc/crawl=CC-MAIN-2025-13/subset=warc/part-00001.parquet",
        ]
        fake_s3 = MagicMock()
        fake_s3.list_objects_v2.return_value = _make_s3_response(keys)
        with _patch_boto3(fake_s3):
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13", max_files=10)

        assert len(urls) == 2
        assert all(u.endswith(".parquet") for u in urls)

    def test_returns_empty_on_client_error(self):
        import lead_crawler.services.common_crawl_duckdb_backend as mod
        fake_s3 = MagicMock()
        fake_s3.list_objects_v2.side_effect = mod._BotoClientError(
            {"Error": {"Code": "403", "Message": "Forbidden"}}, "ListObjectsV2"
        )
        with _patch_boto3(fake_s3):
            urls = CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13")

        assert urls == []

    def test_raises_runtime_error_on_no_credentials(self):
        import lead_crawler.services.common_crawl_duckdb_backend as mod
        fake_s3 = MagicMock()
        fake_s3.list_objects_v2.side_effect = mod._BotoNoCredentialsError()
        with _patch_boto3(fake_s3):
            with pytest.raises(RuntimeError, match="AWS credentials"):
                CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13")

    def test_raises_runtime_error_when_boto3_none(self):
        import lead_crawler.services.common_crawl_duckdb_backend as mod
        with patch.object(mod, "boto3", None):
            with pytest.raises(RuntimeError, match="boto3 is required"):
                CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13")

    def test_constructs_correct_s3_prefix(self):
        fake_s3 = MagicMock()
        fake_s3.list_objects_v2.return_value = _make_s3_response([])
        with _patch_boto3(fake_s3):
            CommonCrawlDuckDbBackend.list_parquet_urls("CC-MAIN-2025-13")
            call_kwargs = fake_s3.list_objects_v2.call_args[1]

        assert call_kwargs["Bucket"] == "commoncrawl"
        assert "CC-MAIN-2025-13" in call_kwargs["Prefix"]
        assert "subset=warc" in call_kwargs["Prefix"]


# ---------------------------------------------------------------------------
# _resolve_dataset
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

        fake_urls = [f"{_CC_HTTPS_BASE}part-0.parquet"]
        with patch.object(backend, "list_parquet_urls", return_value=fake_urls) as mock_list:
            result = backend._resolve_dataset()

        assert mock_list.call_count == 2
        assert result == fake_urls * 2

    def test_returns_empty_when_listing_returns_nothing(self):
        backend = CommonCrawlDuckDbBackend()
        with patch.object(backend, "list_parquet_urls", return_value=[]):
            result = backend._resolve_dataset()
        assert result == []

    def test_propagates_runtime_error_from_missing_credentials(self):
        backend = CommonCrawlDuckDbBackend()
        with patch.object(backend, "list_parquet_urls", side_effect=RuntimeError("AWS credentials")):
            with pytest.raises(RuntimeError, match="AWS credentials"):
                backend._resolve_dataset()


# ---------------------------------------------------------------------------
# fetch_candidates — integration tests using local parquet
# ---------------------------------------------------------------------------

class TestFetchCandidatesSchema:

    def test_returns_matching_candidates(self):
        rows = [{"url": "https://fashion-shop.de/products/shoes",
                 "url_host_tld": "de", "url_host_name": "fashion-shop.de",
                 "fetch_status": 200, "content_mime_detected": "text/html"}]
        results = _fetch(rows, ["/products/"], countries=["de"])
        assert len(results) == 1
        assert "fashion-shop.de" in results[0].normalized_domain

    def test_filters_invalid_status(self):
        rows = [{"url": "https://broken.de/products/item",
                 "url_host_tld": "de", "url_host_name": "broken.de",
                 "fetch_status": 404, "content_mime_detected": "text/html"}]
        assert _fetch(rows, ["/products/"], countries=["de"]) == []

    def test_filters_invalid_mime(self):
        rows = [{"url": "https://shop.de/products/item.pdf",
                 "url_host_tld": "de", "url_host_name": "shop.de",
                 "fetch_status": 200, "content_mime_detected": "application/pdf"}]
        assert _fetch(rows, ["/products/"], countries=["de"]) == []

    def test_tld_filter_uses_url_host_tld(self):
        rows = [
            {"url": "https://german.de/products/jacket", "url_host_tld": "de",
             "url_host_name": "german.de", "fetch_status": 200, "content_mime_detected": "text/html"},
            {"url": "https://dutch.nl/products/jacket", "url_host_tld": "nl",
             "url_host_name": "dutch.nl", "fetch_status": 200, "content_mime_detected": "text/html"},
        ]
        results = _fetch(rows, ["/products/"], countries=["de"])
        domains = [r.normalized_domain for r in results]
        assert any("german" in d for d in domains)
        assert not any("dutch" in d for d in domains)

    def test_no_country_filter_returns_all_tlds(self):
        rows = [
            {"url": "https://a.de/products/item", "url_host_tld": "de",
             "url_host_name": "a.de", "fetch_status": 200, "content_mime_detected": "text/html"},
            {"url": "https://b.ae/products/item", "url_host_tld": "ae",
             "url_host_name": "b.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
        ]
        assert len(_fetch(rows, ["/products/"], countries=None)) == 2

    def test_pattern_filter_applied(self):
        rows = [
            {"url": "https://shop.ae/products/sneakers", "url_host_tld": "ae",
             "url_host_name": "shop.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
            {"url": "https://blog.ae/about", "url_host_tld": "ae",
             "url_host_name": "blog.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
        ]
        results = _fetch(rows, ["/products/"], countries=["ae"])
        assert len(results) == 1

    def test_deduplication_by_domain(self):
        rows = [
            {"url": "https://shop.ae/products/item1", "url_host_tld": "ae",
             "url_host_name": "shop.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
            {"url": "https://shop.ae/products/item2", "url_host_tld": "ae",
             "url_host_name": "shop.ae", "fetch_status": 200, "content_mime_detected": "text/html"},
        ]
        assert len(_fetch(rows, ["/products/"], countries=["ae"])) == 1

    def test_limit_respected(self):
        rows = [
            {"url": f"https://shop{i}.ae/products/item", "url_host_tld": "ae",
             "url_host_name": f"shop{i}.ae", "fetch_status": 200, "content_mime_detected": "text/html"}
            for i in range(10)
        ]
        assert len(_fetch(rows, ["/products/"], countries=["ae"], limit=3)) <= 3

    def test_source_metadata_backend_field(self):
        rows = [{"url": "https://shop.ae/products/bag", "url_host_tld": "ae",
                 "url_host_name": "shop.ae", "fetch_status": 200, "content_mime_detected": "text/html"}]
        results = _fetch(rows, ["/products/"], countries=["ae"])
        assert results[0].source_metadata["backend"] == "duckdb"

    def test_accepts_redirect_statuses(self):
        rows = [
            {"url": "https://shop.ae/products/bag", "url_host_tld": "ae",
             "url_host_name": "shop.ae", "fetch_status": 301, "content_mime_detected": "text/html"},
            {"url": "https://shop2.ae/products/hat", "url_host_tld": "ae",
             "url_host_name": "shop2.ae", "fetch_status": 302, "content_mime_detected": "text/html"},
        ]
        assert len(_fetch(rows, ["/products/"], countries=["ae"])) == 2

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
    def test_https(self):
        assert CommonCrawlDuckDbBackend._is_remote("https://data.commoncrawl.org/part-0.parquet")

    def test_s3(self):
        assert CommonCrawlDuckDbBackend._is_remote("s3://commoncrawl/part.parquet")

    def test_local(self):
        assert not CommonCrawlDuckDbBackend._is_remote("/data/cc/part-0.parquet")

    def test_https_list(self):
        assert CommonCrawlDuckDbBackend._is_remote([
            "https://data.commoncrawl.org/part-0.parquet",
            "https://data.commoncrawl.org/part-1.parquet",
        ])

    def test_local_list(self):
        assert not CommonCrawlDuckDbBackend._is_remote(["/local/part-0.parquet"])
