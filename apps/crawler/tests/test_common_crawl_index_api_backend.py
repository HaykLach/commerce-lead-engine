"""Tests for CommonCrawlIndexApiBackend and its run_worker wiring."""

from __future__ import annotations

import json
import sys
from types import SimpleNamespace
from unittest.mock import MagicMock, Mock, patch

# ---------------------------------------------------------------------------
# Stub heavy optional dependencies before any project import.
#
# _RequestException is *always* a real IOError subclass so it can be used as
# a side_effect on mocks regardless of which requests stub is in sys.modules
# (test_common_crawl_discovery.py installs a bare Mock() before us when pytest
# collects files alphabetically, which would otherwise make the attribute a
# non-exception Mock).
# ---------------------------------------------------------------------------
sys.modules.setdefault("bs4", Mock())

import types as _types


class _RequestException(IOError):
    """Real exception class used as requests.RequestException in every test."""


# Install a thin requests stub only when the slot is still free.
if "requests" not in sys.modules:
    _requests_stub = _types.ModuleType("requests")
    _requests_stub.RequestException = _RequestException  # type: ignore[attr-defined]
    _requests_stub.get = Mock()  # type: ignore[attr-defined]
    _requests_stub.request = Mock()  # keep run_worker happy  # type: ignore[attr-defined]
    sys.modules["requests"] = _requests_stub
else:
    # Ensure the already-loaded stub/module exposes our known exception class
    # so that `except requests.RequestException` in the backend matches what
    # our tests raise via side_effect.
    sys.modules["requests"].RequestException = _RequestException  # type: ignore[attr-defined]

import run_worker  # noqa: E402  (must come after sys.modules patching)
from lead_crawler.services.common_crawl_discovery_service import (  # noqa: E402
    CommonCrawlCandidateRow,
    CommonCrawlDiscoveryService,
)
from lead_crawler.services.common_crawl_domain_filter import CommonCrawlDomainFilter  # noqa: E402
from lead_crawler.services.common_crawl_index_api_backend import (  # noqa: E402
    KNOWN_CRAWLS,
    CommonCrawlIndexApiBackend,
    CommonCrawlIndexApiConfig,
    COUNTRY_TO_TLDS,
)

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_response(status_code: int, text: str) -> Mock:
    resp = Mock()
    resp.status_code = status_code
    resp.text = text
    resp.raise_for_status = Mock()
    if status_code >= 400:
        resp.raise_for_status.side_effect = _RequestException(f"HTTP {status_code}")
    return resp


def _json_line(**kwargs) -> str:
    return json.dumps(kwargs)


def _valid_record(
    url: str = "https://tiny-shop.de/products/item",
    status: str = "200",
    mime: str = "text/html",
    languages: str = "de",
) -> str:
    return _json_line(url=url, status=status, mime=mime, languages=languages)


# ---------------------------------------------------------------------------
# 1. Config defaults
# ---------------------------------------------------------------------------


def test_config_defaults():
    cfg = CommonCrawlIndexApiConfig()
    assert cfg.crawls is None
    assert cfg.tld_targets is None
    assert cfg.requests_per_crawl == 3
    assert cfg.page_size == 100
    assert cfg.request_delay_seconds == 1.0
    assert cfg.timeout_seconds == 20.0
    assert cfg.user_agent  # non-empty string


def test_config_page_size_hard_cap():
    """page_size is capped to 100 regardless of what is passed."""
    cfg = CommonCrawlIndexApiConfig(page_size=9999)
    assert cfg.page_size == 100


def test_known_crawls_has_four_entries():
    assert len(KNOWN_CRAWLS) == 4
    for crawl_id in KNOWN_CRAWLS:
        assert crawl_id.startswith("CC-MAIN-")


# ---------------------------------------------------------------------------
# 2. TLD derivation
# ---------------------------------------------------------------------------


def _backend(tld_targets=None, crawls=None, delay=0.0, page_size=10, requests_per_crawl=1):
    cfg = CommonCrawlIndexApiConfig(
        crawls=crawls or ["CC-MAIN-2025-13"],
        tld_targets=tld_targets,
        requests_per_crawl=requests_per_crawl,
        page_size=page_size,
        request_delay_seconds=delay,
    )
    return CommonCrawlIndexApiBackend(config=cfg)


def test_tld_derivation_from_countries():
    b = _backend()
    tlds = b._get_tlds(["de", "nl"])
    assert "de" in tlds
    assert "nl" in tlds


def test_tld_config_override_ignores_countries():
    b = _backend(tld_targets=["ch"])
    tlds = b._get_tlds(["de", "nl"])
    assert tlds == ["ch"]


def test_tld_derivation_fallback_to_com():
    """Unknown country codes fall back to 'com'."""
    b = _backend()
    tlds = b._get_tlds(["xx"])  # no mapping
    assert tlds == ["com"]


def test_tld_derivation_us_includes_com():
    b = _backend()
    tlds = b._get_tlds(["us"])
    assert "com" in tlds


# ---------------------------------------------------------------------------
# 3. Record filtering — status codes
# ---------------------------------------------------------------------------


def test_valid_statuses_pass():
    b = _backend()
    for status in ("200", "301", "302"):
        record = {"url": "https://x.de/", "status": status, "mime": "text/html"}
        assert b._is_valid_record(record), f"Expected status {status} to pass"


def test_non_200_status_filtered_out():
    b = _backend()
    for bad_status in ("400", "403", "404", "500", ""):
        record = {"url": "https://x.de/", "status": bad_status, "mime": "text/html"}
        assert not b._is_valid_record(record), f"Expected status {bad_status} to be filtered"


# ---------------------------------------------------------------------------
# 4. Record filtering — MIME types
# ---------------------------------------------------------------------------


def test_html_mime_passes():
    b = _backend()
    assert b._is_valid_record({"status": "200", "mime": "text/html; charset=utf-8"})


def test_xhtml_mime_passes():
    b = _backend()
    assert b._is_valid_record({"status": "200", "mime": "application/xhtml+xml"})


def test_non_html_mime_filtered():
    b = _backend()
    for bad_mime in ("application/json", "text/plain", "application/pdf", ""):
        rec = {"status": "200", "mime": bad_mime}
        assert not b._is_valid_record(rec), f"Expected MIME {bad_mime!r} to be filtered"


# ---------------------------------------------------------------------------
# 5. Deduplication
# ---------------------------------------------------------------------------


def test_deduplication_across_pages():
    """The same domain returned by multiple pages/patterns must appear once."""
    b = _backend(requests_per_crawl=2, page_size=10, delay=0.0)

    # Two identical records (same domain)
    duplicate_line = _valid_record(url="https://shop.de/products/1")
    page_text = duplicate_line + "\n" + _valid_record(url="https://shop.de/products/2")

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, page_text)

        results = b.fetch_candidates(patterns=["/products/"], limit=100, countries=["de"])

    # shop.de must appear exactly once despite two URLs from same domain
    domains = [r.normalized_domain for r in results]
    assert domains.count("shop.de") == 1


# ---------------------------------------------------------------------------
# 6. Limit enforcement
# ---------------------------------------------------------------------------


def test_limit_enforced():
    """fetch_candidates never returns more than limit unique domains."""
    b = _backend(requests_per_crawl=10, page_size=10, delay=0.0)

    lines = "\n".join(
        _valid_record(url=f"https://shop{i}.de/products/x") for i in range(50)
    )

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, lines)

        results = b.fetch_candidates(patterns=["/products/"], limit=5, countries=["de"])

    assert len(results) <= 5


# ---------------------------------------------------------------------------
# 7. HTTP 404 handling — index not found for a crawl → treated as empty page
# ---------------------------------------------------------------------------


def test_http_404_returns_empty_and_continues():
    """A 404 response is treated as an empty page; no exception is raised."""
    b = _backend(requests_per_crawl=1, delay=0.0)

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(404, "")

        results = b.fetch_candidates(patterns=["/products/"], limit=100, countries=["de"])

    assert results == []


# ---------------------------------------------------------------------------
# 8. RequestException handling — logs and continues
# ---------------------------------------------------------------------------


def test_request_exception_is_handled_gracefully():
    """A network error must not propagate; the backend returns whatever was collected."""
    b = _backend(requests_per_crawl=1, delay=0.0)

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = _RequestException("network down")

        # Must not raise
        results = b.fetch_candidates(patterns=["/products/"], limit=100, countries=["de"])

    assert results == []


# ---------------------------------------------------------------------------
# 9. source_metadata fields
# ---------------------------------------------------------------------------


def test_source_metadata_fields():
    crawl_id = "CC-MAIN-2025-13"
    b = _backend(tld_targets=["de"], crawls=[crawl_id], delay=0.0)

    line = _json_line(
        url="https://tiny-shop.de/products/item",
        status="200",
        mime="text/html",
        languages="de",
    )

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, line)

        results = b.fetch_candidates(patterns=["/products/"], limit=1, countries=["de"])

    assert results, "Expected at least one result"
    meta = results[0].source_metadata
    assert meta["backend"] == "cc_index_api"
    assert meta["crawl"] == crawl_id
    assert meta["tld_target"] == "de"
    assert meta["status"] == "200"
    assert meta["mime"] == "text/html"
    assert meta["languages"] == "de"


# ---------------------------------------------------------------------------
# 10. Integration with CommonCrawlDiscoveryService + CommonCrawlDomainFilter
# ---------------------------------------------------------------------------


def test_integration_giants_blocked():
    """Well-known enterprise giants must be blocked by CommonCrawlDomainFilter."""
    b = _backend(tld_targets=["de"], delay=0.0)

    lines = "\n".join([
        _valid_record(url="https://zalando.de/products/dress"),
        _valid_record(url="https://otto.de/products/tv"),
        _valid_record(url="https://tiny-boutique.de/products/hat"),
    ])

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, lines)

        service = CommonCrawlDiscoveryService(
            backend=b,
            domain_filter=CommonCrawlDomainFilter(),
        )
        discovered = service.discover(patterns=["/products/"], limit=100, countries=["de"])

    domains = [c.domain for c in discovered]
    assert "zalando.de" not in domains
    assert "otto.de" not in domains
    assert "tiny-boutique.de" in domains


def test_integration_source_type():
    """Discovered candidates must carry source_type='common_crawl'."""
    b = _backend(tld_targets=["de"], delay=0.0)
    line = _valid_record(url="https://shop-alpha.de/products/item")

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, line)

        service = CommonCrawlDiscoveryService(
            backend=b,
            domain_filter=CommonCrawlDomainFilter(),
        )
        discovered = service.discover(patterns=["/products/"], limit=10, countries=["de"])

    assert all(c.source_type == "common_crawl" for c in discovered)


# ---------------------------------------------------------------------------
# 11. _build_common_crawl_backend dispatch — cc_index_api
# ---------------------------------------------------------------------------


def test_build_backend_cc_index_api_basic():
    payload = {
        "backend": "cc_index_api",
        "cc_crawls": ["CC-MAIN-2025-13", "CC-MAIN-2024-51"],
        "cc_tld_targets": ["de", "nl"],
        "cc_requests_per_crawl": 2,
        "cc_page_size": 50,
        "cc_delay": 0.5,
    }
    backend, name = run_worker._build_common_crawl_backend(payload)

    assert name == "cc_index_api"
    assert isinstance(backend, CommonCrawlIndexApiBackend)
    assert backend.config.crawls == ["CC-MAIN-2025-13", "CC-MAIN-2024-51"]
    assert backend.config.tld_targets == ["de", "nl"]
    assert backend.config.requests_per_crawl == 2
    assert backend.config.page_size == 50
    assert backend.config.request_delay_seconds == 0.5


def test_build_backend_cc_index_api_page_size_capped():
    """page_size is capped at 100 even when the payload sends a larger value."""
    payload = {"backend": "cc_index_api", "cc_page_size": 999}
    backend, _ = run_worker._build_common_crawl_backend(payload)
    assert backend.config.page_size == 100


def test_build_backend_cc_index_api_defaults():
    """All optional payload keys fall back to sensible defaults."""
    payload = {"backend": "cc_index_api"}
    backend, name = run_worker._build_common_crawl_backend(payload)

    assert name == "cc_index_api"
    assert backend.config.crawls is None  # → uses KNOWN_CRAWLS at runtime
    assert backend.config.tld_targets is None
    assert backend.config.requests_per_crawl == 3
    assert backend.config.page_size == 100
    assert backend.config.request_delay_seconds == 1.0


# ---------------------------------------------------------------------------
# 12. Unknown backend raises ValueError
# ---------------------------------------------------------------------------


def test_build_backend_unknown_raises():
    import pytest
    with pytest.raises(ValueError, match="Unsupported"):
        run_worker._build_common_crawl_backend({"backend": "nonexistent"})


# ---------------------------------------------------------------------------
# 13. process_domain_discovery_common_crawl happy path (cc_index_api)
# ---------------------------------------------------------------------------


def test_process_domain_discovery_happy_path(monkeypatch):
    class FakeService:
        def discover(self, patterns, limit, countries, niches):
            return [
                SimpleNamespace(
                    domain="tiny-shop.de",
                    source_type="common_crawl",
                    source_url="https://tiny-shop.de/products/item",
                    source_context={"matched_pattern": "/products/", "backend": "cc_index_api"},
                )
            ]

    monkeypatch.setattr(
        run_worker, "_build_common_crawl_backend", lambda p: (object(), "cc_index_api")
    )
    monkeypatch.setattr(
        run_worker, "CommonCrawlDomainFilter", lambda *args, **kwargs: object()
    )
    monkeypatch.setattr(
        run_worker,
        "CommonCrawlDiscoveryService",
        lambda backend, domain_filter: FakeService(),
    )

    ingested = []
    monkeypatch.setattr(
        run_worker,
        "ingest_discovered_domain",
        lambda candidate, payload: ingested.append(candidate),
    )

    result = run_worker.process_domain_discovery_common_crawl(
        {
            "crawl_payload": {
                "job_type": "domain_discovery_common_crawl",
                "backend": "cc_index_api",
                "patterns": ["/products/"],
                "limit": 5,
                "countries": ["de"],
            }
        }
    )

    assert result["job_type"] == "domain_discovery_common_crawl"
    assert result["backend"] == "cc_index_api"
    assert result["ingested_count"] == 1
    assert ingested[0]["domain"] == "tiny-shop.de"
    assert ingested[0]["source_type"] == "common_crawl"


# ---------------------------------------------------------------------------
# 14. Pagination stops early when page returns < page_size/2 rows
# ---------------------------------------------------------------------------


def test_early_stop_on_sparse_page():
    """When a page returns fewer than page_size/2 rows, pagination stops."""
    b = _backend(tld_targets=["de"], crawls=["CC-MAIN-2025-13"], requests_per_crawl=5, page_size=10, delay=0.0)

    # Only 2 rows on the first page (< 10/2 = 5) → should stop after page 0
    two_rows = "\n".join([
        _valid_record(url="https://a.de/products/x"),
        _valid_record(url="https://b.de/products/x"),
    ])

    call_count = 0

    def fake_get(url, **kwargs):
        nonlocal call_count
        call_count += 1
        return _make_response(200, two_rows)

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = fake_get

        b.fetch_candidates(patterns=["/products/"], limit=100, countries=["de"])

    # Should have made exactly 1 request (stopped after first sparse page)
    assert call_count == 1
