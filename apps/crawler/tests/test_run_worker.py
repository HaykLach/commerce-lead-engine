import sys
from types import SimpleNamespace
from unittest.mock import Mock

sys.modules.setdefault("requests", Mock())
sys.modules.setdefault("bs4", Mock())

import run_worker


class DummyResponse:
    def __init__(self, status_code: int, payload=None, text: str = ""):
        self.status_code = status_code
        self._payload = payload
        self.text = text

    def json(self):
        return self._payload


def test_get_next_job_returns_none_on_204(monkeypatch):
    def fake_request(**kwargs):
        return DummyResponse(status_code=204)

    monkeypatch.setattr(run_worker.requests, "request", fake_request)

    assert run_worker.get_next_job() is None


def test_get_next_job_returns_payload_on_200(monkeypatch):
    expected = {
        "id": 15,
        "status": "queued",
        "trigger_type": "manual",
        "crawl_payload": {"job_type": "homepage_fetch", "domain": "example.com"},
    }

    def fake_request(**kwargs):
        return DummyResponse(status_code=200, payload={"data": expected}, text='{"id": 15}')

    monkeypatch.setattr(run_worker.requests, "request", fake_request)

    assert run_worker.get_next_job() == expected


def test_mark_job_completed_calls_new_internal_endpoint(monkeypatch):
    calls = []

    def fake_request(**kwargs):
        calls.append(SimpleNamespace(**kwargs))
        return DummyResponse(status_code=200, text='{"ok": true}')

    monkeypatch.setattr(run_worker.requests, "request", fake_request)

    run_worker.mark_job_completed(22, {"pages": 3})

    assert len(calls) == 1
    assert calls[0].url.endswith('/crawl-jobs/22/complete')
    assert calls[0].method == 'POST'
    assert calls[0].json == {"summary": {"pages": 3}}


def test_normalize_domain_strips_scheme_and_www():
    assert run_worker.normalize_domain("https://WWW.Example.com/") == "example.com"


def test_infer_niche_detects_b2b_terms():
    html = """
    <html>
      <head><title>Acme Wholesale Distributor</title></head>
      <body>Request quote for bulk order. MOQ available for dealers.</body>
    </html>
    """
    niche, scores = run_worker.infer_niche(html, {"description": "RFQ and reseller trade portal"})

    assert niche == "b2b"
    assert scores["b2b"] > 0


def test_process_page_classification_persists_summary(monkeypatch):
    captured = {}

    class FakePageClassificationService:
        def classify_domain(self, domain: str, max_pages: int = 12):
            assert domain == "example.com"
            assert max_pages == 10
            return SimpleNamespace(
                domain=domain,
                product_page_found=True,
                category_page_found=True,
                cart_page_found=True,
                checkout_page_found=False,
                sample_product_url="https://example.com/products/sku-1",
                sample_category_url="https://example.com/collections/main",
                sample_cart_url="https://example.com/cart",
                sample_checkout_url=None,
                product_count_guess=240,
                product_count_bucket="201-1000",
                classification_metadata={"sampled_urls": ["https://example.com/"]},
                classified_at="2026-04-18T00:00:00Z",
            )

    def fake_persist(job, domain_snapshot, classification):
        captured["job"] = job
        captured["domain_snapshot"] = domain_snapshot
        captured["classification"] = classification

    monkeypatch.setattr(run_worker, "PageClassificationService", FakePageClassificationService)
    monkeypatch.setattr(run_worker, "persist_page_classification", fake_persist)

    result = run_worker.process_page_classification(
        {
            "id": 200,
            "domain_id": 15,
            "crawl_payload": {
                "job_type": "page_classification",
                "domain": "example.com",
                "max_pages": 10,
            },
        }
    )

    assert result["job_type"] == "page_classification"
    assert result["product_count_guess"] == 240
    assert captured["domain_snapshot"]["id"] == 15


def test_process_homepage_fetch_enqueues_page_classification(monkeypatch):
    class FakeHomepageFetchService:
        def fetch(self, domain):
            return {
                "final_url": f"https://{domain}",
                "status_code": 200,
                "html": "<html></html>",
                "meta": {},
                "scripts": [],
                "stylesheets": [],
                "links": [],
            }

    class FakeWhatWebService:
        def scan(self, domain):
            return SimpleNamespace(target_url=f"https://{domain}", plugins=[], raw_payload={}, error=None)

    class FakeEngine:
        def detect(self, _):
            return {"platform": "shopify", "confidence": 90, "signals": []}

    enqueue_calls = []

    monkeypatch.setattr(run_worker, "HomepageFetchService", FakeHomepageFetchService)
    monkeypatch.setattr(run_worker, "WhatWebRunnerService", lambda: FakeWhatWebService())
    monkeypatch.setattr(run_worker, "FingerprintRuleEngine", lambda: FakeEngine())
    monkeypatch.setattr(run_worker, "persist_domain_snapshot", lambda *_: {"id": 44, "normalized_domain": "example.com"})
    monkeypatch.setattr(run_worker, "persist_fingerprint_record", lambda *_: None)
    monkeypatch.setattr(run_worker, "enqueue_page_classification_job", lambda *args: enqueue_calls.append(args))

    summary = run_worker.process_homepage_fetch({"id": 50, "trigger_type": "manual", "crawl_payload": {"job_type": "homepage_fetch", "domain": "example.com"}})

    assert summary["domain_id"] == 44
    assert summary["enqueued_page_classification"] is True
    assert len(enqueue_calls) == 1


def test_process_domain_discovery_search_seed_ingests_domains(monkeypatch):
    class FakeService:
        def discover(self, keywords, countries, limit):
            assert keywords == ["fashion"]
            assert countries == ["de"]
            assert limit == 2
            return [
                SimpleNamespace(domain="alpha.com", source_type="search_seed", keyword_seed="fashion de shop", source_url="https://search/a"),
                SimpleNamespace(domain="beta.com", source_type="search_seed", keyword_seed="fashion de store", source_url="https://search/b"),
            ]

    ingested = []
    monkeypatch.setattr(run_worker, "SearchSeedDiscoveryService", lambda: FakeService())
    monkeypatch.setattr(run_worker, "ingest_discovered_domain", lambda candidate, payload: ingested.append((candidate, payload)))

    result = run_worker.process_domain_discovery_search_seed({
        "crawl_payload": {"job_type": "domain_discovery_search_seed", "keywords": ["fashion"], "countries": ["de"], "limit": 2}
    })

    assert result["job_type"] == "domain_discovery_search_seed"
    assert result["ingested_count"] == 2
    assert len(ingested) == 2


def test_process_domain_discovery_directory_ingests_domains(monkeypatch):
    class FakeService:
        def discover(self, directory_urls, limit):
            assert directory_urls == ["https://directory.example/list"]
            assert limit == 1
            return [
                SimpleNamespace(domain="gamma.com", source_type="directory", keyword_seed=None, source_url="https://directory.example/list"),
            ]

    ingested = []
    monkeypatch.setattr(run_worker, "DirectoryDiscoveryService", lambda: FakeService())
    monkeypatch.setattr(run_worker, "ingest_discovered_domain", lambda candidate, payload: ingested.append((candidate, payload)))

    result = run_worker.process_domain_discovery_directory({
        "crawl_payload": {"job_type": "domain_discovery_directory", "directory_urls": ["https://directory.example/list"], "limit": 1}
    })

    assert result["job_type"] == "domain_discovery_directory"
    assert result["ingested_count"] == 1
    assert ingested[0][0]["domain"] == "gamma.com"


def test_process_domain_discovery_expansion_ingests_domains(monkeypatch):
    class FakeService:
        def discover(self, domains, limit):
            assert domains == ["seed.com"]
            assert limit == 1
            return [
                SimpleNamespace(domain="delta.com", source_type="expansion", keyword_seed="seed.com", source_url="https://seed.com/partners"),
            ]

    ingested = []
    monkeypatch.setattr(run_worker, "ExpansionDiscoveryService", lambda: FakeService())
    monkeypatch.setattr(run_worker, "ingest_discovered_domain", lambda candidate, payload: ingested.append((candidate, payload)))

    result = run_worker.process_domain_discovery_expansion({
        "crawl_payload": {"job_type": "domain_discovery_expansion", "domains": ["seed.com"], "limit": 1}
    })

    assert result["job_type"] == "domain_discovery_expansion"
    assert result["ingested_count"] == 1
    assert ingested[0][0]["source_type"] == "expansion"


def test_process_common_crawl_import(monkeypatch):
    class FakeImporter:
        def run_import(self, payload):
            assert payload["job_type"] == "common_crawl_import"
            return {"job_type": "common_crawl_import", "domains_upserted": 12}

    monkeypatch.setattr(run_worker, "CommonCrawlImporter", lambda: FakeImporter())
    result = run_worker.process_common_crawl_import(
        {"crawl_payload": {"job_type": "common_crawl_import", "backend": "duckdb_import"}}
    )

    assert result["job_type"] == "common_crawl_import"
    assert result["domains_upserted"] == 12


def test_process_domain_discovery_local_index_forces_local_backend(monkeypatch):
    def fake_process(job):
        assert job["crawl_payload"]["backend"] == "local_index"
        return {"job_type": "domain_discovery_common_crawl", "backend": "local_index", "ingested_count": 1}

    monkeypatch.setattr(run_worker, "process_domain_discovery_common_crawl", fake_process)

    result = run_worker.process_domain_discovery_local_index(
        {"crawl_payload": {"job_type": "domain_discovery_local_index", "countries": ["de"], "limit": 10}}
    )

    assert result["job_type"] == "domain_discovery_local_index"
    assert result["backend"] == "local_index"
