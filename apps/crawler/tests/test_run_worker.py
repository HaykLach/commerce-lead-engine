import sys
from types import SimpleNamespace
from unittest.mock import Mock

sys.modules.setdefault("requests", Mock())

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
