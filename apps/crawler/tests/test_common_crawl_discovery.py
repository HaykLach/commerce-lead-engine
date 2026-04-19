import sys
from types import SimpleNamespace
from unittest.mock import Mock

sys.modules.setdefault("requests", Mock())
sys.modules.setdefault("bs4", Mock())

import run_worker
from lead_crawler.services.common_crawl_athena_backend import CommonCrawlAthenaBackend, CommonCrawlAthenaConfig
from lead_crawler.services.common_crawl_discovery_service import (
    CommonCrawlCandidateRow,
    CommonCrawlDiscoveryService,
)
from lead_crawler.services.common_crawl_domain_filter import CommonCrawlDomainFilter
from lead_crawler.services.common_crawl_url_pattern_builder import CommonCrawlUrlPatternBuilder


class FakeBackend:
    def fetch_candidates(self, patterns, limit, countries=None, niches=None):
        _ = (patterns, limit, countries, niches)
        return [
            CommonCrawlCandidateRow(
                candidate_url="https://tiny-shop.de/products/item-1",
                normalized_domain="tiny-shop.de",
                matched_pattern="/products/",
                source_metadata={"backend": "duckdb", "crawl": "CC-MAIN-2026-01"},
            ),
            CommonCrawlCandidateRow(
                candidate_url="https://amazon.com/cart",
                normalized_domain="amazon.com",
                matched_pattern="/cart",
                source_metadata={"backend": "duckdb", "crawl": "CC-MAIN-2026-01"},
            ),
        ]


def test_common_crawl_pattern_builder_generates_like_clause():
    clause = CommonCrawlUrlPatternBuilder.like_clauses("url", ["/products/", "/cart"])

    assert "lower(url) LIKE '%/products/%'" in clause
    assert "lower(url) LIKE '%/cart%'" in clause


def test_common_crawl_domain_filter_blocks_known_giants():
    domain_filter = CommonCrawlDomainFilter()

    assert domain_filter.should_include("tiny-shop.de") is True
    assert domain_filter.should_include("amazon.com") is False


def test_common_crawl_discovery_service_filters_and_deduplicates():
    service = CommonCrawlDiscoveryService(backend=FakeBackend(), domain_filter=CommonCrawlDomainFilter())

    discovered = service.discover(patterns=["/products/"], limit=20)

    assert len(discovered) == 1
    assert discovered[0].domain == "tiny-shop.de"
    assert discovered[0].source_type == "common_crawl"


def test_athena_backend_query_builder_contains_filters():
    backend = CommonCrawlAthenaBackend(
        config=CommonCrawlAthenaConfig(
            database="commoncrawl",
            table="ccindex",
            output_location="s3://bucket/results/",
        )
    )

    query = backend.build_query(patterns=["/products/"], limit=50, countries=["de"], niches=["fashion"])

    assert "FROM commoncrawl.ccindex" in query
    assert "lower(url) LIKE '%/products/%'" in query
    assert "lower(coalesce(country, '')) IN ('de')" in query
    assert "LIMIT 250" in query


def test_process_domain_discovery_common_crawl_happy_path(monkeypatch):
    class FakeService:
        def discover(self, patterns, limit, countries, niches):
            assert "/products/" in patterns
            assert limit == 2
            assert countries == ["de"]
            assert niches == ["fashion"]
            return [
                SimpleNamespace(
                    domain="tiny-shop.de",
                    source_type="common_crawl",
                    source_url="https://tiny-shop.de/products/item-1",
                    source_context={"matched_pattern": "/products/", "backend": "duckdb"},
                )
            ]

    monkeypatch.setattr(run_worker, "_build_common_crawl_backend", lambda payload: (object(), "duckdb"))
    monkeypatch.setattr(run_worker, "CommonCrawlDomainFilter", lambda *_: object())
    monkeypatch.setattr(run_worker, "CommonCrawlDiscoveryService", lambda backend, domain_filter: FakeService())

    ingested = []
    monkeypatch.setattr(run_worker, "ingest_discovered_domain", lambda candidate, payload: ingested.append((candidate, payload)))

    result = run_worker.process_domain_discovery_common_crawl(
        {
            "crawl_payload": {
                "job_type": "domain_discovery_common_crawl",
                "patterns": ["/products/"],
                "limit": 2,
                "countries": ["de"],
                "niches": ["fashion"],
                "backend": "duckdb",
            }
        }
    )

    assert result["job_type"] == "domain_discovery_common_crawl"
    assert result["ingested_count"] == 1
    assert ingested[0][0]["source_context"]["matched_pattern"] == "/products/"
