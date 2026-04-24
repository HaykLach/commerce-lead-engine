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
    assert cfg.timeout_seconds == 60.0
    assert cfg.max_retries == 2
    assert cfg.retry_base_delay_seconds == 8.0
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


def test_tld_derivation_skips_oversized_tlds():
    """.de and .nl are oversized and excluded when derived from countries."""
    b = _backend()
    tlds = b._get_tlds(["de", "nl"])
    assert "de" not in tlds
    assert "nl" not in tlds


def test_tld_config_override_bypasses_oversized_guard():
    """Explicit tld_targets override lets callers force-include any TLD."""
    b = _backend(tld_targets=["de", "nl"])
    tlds = b._get_tlds(["ae"])   # country arg is ignored when tld_targets set
    assert "de" in tlds
    assert "nl" in tlds


def test_tld_derivation_small_tlds_pass():
    """Small TLDs (.ae, .ch, .at, .se) are included when derived from countries."""
    b = _backend()
    tlds = b._get_tlds(["ae", "ch"])
    assert "ae" in tlds
    assert "ch" in tlds


def test_tld_derivation_unknown_country_returns_empty():
    """Unknown country codes produce an empty list (no fallback to .com)."""
    b = _backend()
    tlds = b._get_tlds(["xx"])
    assert tlds == []


def test_tld_derivation_us_returns_us_only():
    """US maps to .us only — .com is in _OVERSIZED_TLDS and is skipped."""
    b = _backend()
    tlds = b._get_tlds(["us"])
    assert "us" in tlds
    assert "com" not in tlds


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
    """The same domain returned by multiple pages must appear once."""
    b = _backend(tld_targets=["ae"], requests_per_crawl=2, page_size=10, delay=0.0)

    duplicate_line = _valid_record(url="https://shop.ae/products/1")
    page_text = duplicate_line + "\n" + _valid_record(url="https://shop.ae/products/2")

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, page_text)

        results = b.fetch_candidates(patterns=["/products/"], limit=100, countries=[])

    domains = [r.normalized_domain for r in results]
    assert domains.count("shop.ae") == 1


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
    b = _backend(tld_targets=["ae"], crawls=[crawl_id], delay=0.0)

    line = _json_line(
        url="https://tiny-shop.ae/products/item",
        status="200",
        mime="text/html",
        languages="ar",
    )

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, line)

        results = b.fetch_candidates(patterns=["/products/"], limit=1, countries=[])

    assert results, "Expected at least one result"
    meta = results[0].source_metadata
    assert meta["backend"] == "cc_index_api"
    assert meta["crawl"] == crawl_id
    assert meta["tld_target"] == "ae"
    assert meta["status"] == "200"
    assert meta["mime"] == "text/html"
    assert meta["languages"] == "ar"


# ---------------------------------------------------------------------------
# 10. Integration with CommonCrawlDiscoveryService + CommonCrawlDomainFilter
# ---------------------------------------------------------------------------


def test_integration_giants_blocked():
    """Well-known enterprise giants must be blocked by CommonCrawlDomainFilter."""
    b = _backend(tld_targets=["ae"], delay=0.0)

    lines = "\n".join([
        _valid_record(url="https://amazon.com/products/x"),
        _valid_record(url="https://ebay.com/products/y"),
        _valid_record(url="https://tiny-boutique.ae/products/hat"),
    ])

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, lines)

        service = CommonCrawlDiscoveryService(
            backend=b,
            domain_filter=CommonCrawlDomainFilter(),
        )
        discovered = service.discover(patterns=["/products/"], limit=100, countries=[])

    domains = [c.domain for c in discovered]
    assert "amazon.com" not in domains
    assert "ebay.com" not in domains
    assert "tiny-boutique.ae" in domains


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


# ---------------------------------------------------------------------------
# 14b. URL is a filter-free page-seek (no server-side filter= at all)
# ---------------------------------------------------------------------------


def test_request_url_is_filter_free():
    """The CDX request must use a plain page-seek with no filter= parameters.

    Any server-side filter forces a sequential scan over the TLD range, which
    causes 504 timeouts.  All filtering is done locally after the page is fetched.
    """
    b = _backend(tld_targets=["de"], crawls=["CC-MAIN-2025-13"], delay=0.0)
    url = b._build_request_url("CC-MAIN-2025-13", "de", 0)

    assert "url=*.de" in url          # single TLD wildcard
    assert "output=json" in url
    assert "page=0" in url
    assert "filter=" not in url       # zero server-side filters


def test_request_url_page_parameter():
    b = _backend(tld_targets=["ae"], crawls=["CC-MAIN-2025-13"], delay=0.0)
    url0 = b._build_request_url("CC-MAIN-2025-13", "ae", 0)
    url1 = b._build_request_url("CC-MAIN-2025-13", "ae", 1)
    assert "page=0" in url0
    assert "page=1" in url1


def test_local_path_matching_accepts_matching_url():
    """_match_pattern returns the matched segment when the URL contains it."""
    b = _backend()
    assert b._match_pattern("https://shop.de/products/shirt", ["products", "checkout"]) == "products"
    assert b._match_pattern("https://shop.de/checkout", ["products", "checkout"]) == "checkout"


def test_local_path_matching_rejects_non_matching_url():
    b = _backend()
    assert b._match_pattern("https://shop.de/about-us", ["products", "checkout"]) is None


def test_local_path_matching_filters_records_without_pattern():
    """Records whose URL does not contain any target path segment are skipped."""
    b = _backend(tld_targets=["ae"], crawls=["CC-MAIN-2025-13"], delay=0.0)

    lines = "\n".join([
        _valid_record(url="https://a.ae/about-us"),        # no match
        _valid_record(url="https://b.ae/products/shirt"),  # match
        _valid_record(url="https://c.ae/contact"),         # no match
    ])

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(200, lines)

        results = b.fetch_candidates(patterns=["/products/"], limit=100, countries=[])

    domains = [r.normalized_domain for r in results]
    assert "b.ae" in domains
    assert "a.ae" not in domains
    assert "c.ae" not in domains


def test_single_cdx_request_per_page_checks_all_patterns():
    """One HTTP request per page; all patterns checked locally — no per-pattern requests."""
    b = _backend(tld_targets=["ae"], crawls=["CC-MAIN-2025-13"], requests_per_crawl=1, delay=0.0)

    lines = "\n".join([
        _valid_record(url="https://x.ae/shop/hats"),
        _valid_record(url="https://y.ae/checkout"),
    ])

    call_count = 0

    def fake_get(url, **kwargs):
        nonlocal call_count
        call_count += 1
        return _make_response(200, lines)

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = fake_get

        results = b.fetch_candidates(
            patterns=["/shop/", "/checkout"], limit=100, countries=[]
        )

    # Only 1 HTTP request (not 1 per pattern)
    assert call_count == 1
    domains = [r.normalized_domain for r in results]
    assert "x.ae" in domains
    assert "y.ae" in domains


# ---------------------------------------------------------------------------
# 14c. 504 / 503 / 502 handling — skips combo, continues next
# ---------------------------------------------------------------------------


def test_http_504_retries_then_skips():
    """504 is retried up to max_retries times, then the combination is skipped."""
    b = _backend(tld_targets=["ae"], crawls=["CC-MAIN-2025-13"], requests_per_crawl=1, delay=0.0)
    b.config.max_retries = 2
    b.config.retry_base_delay_seconds = 0.0  # no sleep in tests

    call_count = 0

    def fake_get(url, **kwargs):
        nonlocal call_count
        call_count += 1
        return _make_response(504, "")

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = fake_get

        results = b.fetch_candidates(patterns=["/products/"], limit=50, countries=[])

    # 1 original attempt + 2 retries = 3 total calls
    assert call_count == 3
    assert results == []


def test_http_504_succeeds_on_retry():
    """504 on first attempt followed by 200 on retry yields results."""
    b = _backend(tld_targets=["ae"], crawls=["CC-MAIN-2025-13"], requests_per_crawl=1, delay=0.0)
    b.config.max_retries = 1
    b.config.retry_base_delay_seconds = 0.0

    responses = [
        _make_response(504, ""),
        _make_response(200, _valid_record(url="https://good-shop.ae/products/item")),
    ]
    call_count = 0

    def fake_get(url, **kwargs):
        nonlocal call_count
        resp = responses[min(call_count, len(responses) - 1)]
        call_count += 1
        return resp

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = fake_get

        results = b.fetch_candidates(patterns=["/products/"], limit=50, countries=[])

    assert call_count == 2  # 1 fail + 1 success
    assert any(r.normalized_domain == "good-shop.ae" for r in results)


def test_http_504_skips_tld_and_continues_to_next():
    """After exhausting retries on one TLD, the backend continues to the next."""
    b = _backend(
        tld_targets=["ae", "ch"],
        crawls=["CC-MAIN-2025-13"],
        requests_per_crawl=1,
        delay=0.0,
    )
    b.config.max_retries = 0  # no retries — fail fast
    b.config.retry_base_delay_seconds = 0.0

    def fake_get(url, **kwargs):
        if "*.ae" in url:
            return _make_response(504, "")
        return _make_response(200, _valid_record(url="https://klein-shop.ch/products/x"))

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = fake_get

        results = b.fetch_candidates(patterns=["/products/"], limit=50, countries=[])

    domains = [r.normalized_domain for r in results]
    assert "klein-shop.ch" in domains


def test_http_503_retried_same_as_504():
    b = _backend(tld_targets=["ae"], crawls=["CC-MAIN-2025-13"], requests_per_crawl=1, delay=0.0)
    b.config.max_retries = 0
    b.config.retry_base_delay_seconds = 0.0

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.return_value = _make_response(503, "")

        results = b.fetch_candidates(patterns=["/products/"], limit=50, countries=[])

    assert results == []


# ---------------------------------------------------------------------------
# 14d. Oversized TLD guard (.com / .net / .org / .de / .nl)
# ---------------------------------------------------------------------------


def test_oversized_tld_from_countries_makes_no_request():
    """When countries=['de'] the derived TLD is oversized — no HTTP call made."""
    b = _backend(crawls=["CC-MAIN-2025-13"], delay=0.0)

    call_count = 0

    def fake_get(url, **kwargs):
        nonlocal call_count
        call_count += 1
        return _make_response(200, _valid_record())

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = fake_get

        results = b.fetch_candidates(patterns=["/products/"], limit=50, countries=["de"])

    assert call_count == 0, "Expected zero HTTP calls — .de is oversized"
    assert results == []


def test_oversized_tld_via_explicit_targets_still_skips():
    """Even explicit tld_targets=[com] results in zero requests (oversized guard)."""
    b = _backend(tld_targets=["com"], crawls=["CC-MAIN-2025-13"], delay=0.0)
    b.config.max_retries = 0

    call_count = 0

    def fake_get(url, **kwargs):
        nonlocal call_count
        call_count += 1
        return _make_response(200, _valid_record())

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = fake_get

        results = b.fetch_candidates(patterns=["/products/"], limit=50, countries=[])

    assert call_count == 0
    assert results == []


def test_us_country_does_not_trigger_com_query():
    """.com must not be queried when countries=['us'] — .us is used instead."""
    b = _backend(crawls=["CC-MAIN-2025-13"], delay=0.0)
    b.config.max_retries = 0

    urls_called: list[str] = []

    def fake_get(url, **kwargs):
        urls_called.append(url)
        return _make_response(200, "")

    with patch("lead_crawler.services.common_crawl_index_api_backend.requests") as mock_req:
        mock_req.RequestException = _RequestException
        mock_req.get.side_effect = fake_get

        b.fetch_candidates(patterns=["/products/"], limit=50, countries=["us"])

    for url in urls_called:
        assert "*.com" not in url, f"Unexpected .com query: {url}"


def test_early_stop_on_sparse_page():
    """When a page returns fewer than page_size/2 rows, pagination stops."""
    b = _backend(tld_targets=["ae"], crawls=["CC-MAIN-2025-13"], requests_per_crawl=5, page_size=10, delay=0.0)

    # Only 2 rows on the first page (< 10/2 = 5) → should stop after page 0
    two_rows = "\n".join([
        _valid_record(url="https://a.ae/products/x"),
        _valid_record(url="https://b.ae/products/x"),
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
