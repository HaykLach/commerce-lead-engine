"""Smoke tests for crawler skeleton placeholders."""

from lead_crawler.fingerprint.rule_engine import FingerprintRuleEngine
from lead_crawler.services.sitemap_parser_service import SitemapParserService


def test_fingerprint_engine_placeholder_label() -> None:
    """Ensure fingerprint rule engine placeholder returns a stable default label."""
    engine = FingerprintRuleEngine()
    assert engine.detect_platform(signals=[]) == "unknown"


def test_sitemap_parser_placeholder_output() -> None:
    """Ensure sitemap parser placeholder returns an empty URL list."""
    parser = SitemapParserService()
    assert parser.extract_urls(sitemap_xml="<urlset/>") == []
