from lead_crawler.services.domain_extraction_helpers import DomainExtractionHelpers
from lead_crawler.services.domain_normalizer import DomainNormalizer
from lead_crawler.services.directory_discovery_service import DirectoryDiscoveryService
from lead_crawler.services.expansion_discovery_service import ExpansionDiscoveryService
from lead_crawler.services.search_seed_discovery_service import SearchSeedDiscoveryService


class FakeSearchStrategy:
    def fetch_result_pages(self, query: str):
        _ = query
        return [
            (
                "https://search.example.test/results",
                """
                <html>
                    <a href=\"https://shop-a.com\">A</a>
                    <a href=\"mailto:test@example.com\">mail</a>
                    <a href=\"https://www.shop-b.com/page\">B</a>
                </html>
                """,
            )
        ]


def test_domain_normalizer_rejects_invalid_values():
    normalizer = DomainNormalizer()

    assert normalizer.normalize("https://www.ExampleStore.com/path") == "examplestore.com"
    assert normalizer.normalize("mailto:test@example.com") is None
    assert normalizer.normalize("localhost") is None


def test_domain_extraction_ignores_invalid_links():
    extractor = DomainExtractionHelpers()

    domains = extractor.extract_external_domains(
        html="""
        <a href=\"mailto:hello@example.com\">mail</a>
        <a href=\"javascript:void(0)\">js</a>
        <a href=\"#top\">fragment</a>
        <a href=\"https://external-shop.com/catalog\">ext</a>
        """,
        base_url="https://directory.example.org/list",
    )

    assert domains == {"external-shop.com"}


def test_search_seed_discovery_happy_path():
    service = SearchSeedDiscoveryService(source_strategy=FakeSearchStrategy())

    discovered = service.discover(["fashion"], ["de"], limit=10)

    assert {item.domain for item in discovered} == {"shop-a.com", "shop-b.com"}
    assert all(item.source_type == "search_seed" for item in discovered)


def test_directory_discovery_happy_path(monkeypatch):
    class DummyResponse:
        def __init__(self, text):
            self.text = text

        def raise_for_status(self):
            return None

    def fake_get(url, **kwargs):
        _ = kwargs
        return DummyResponse("<a href='https://vendor-one.com'>Vendor</a>")

    monkeypatch.setattr("lead_crawler.services.directory_discovery_service.requests.get", fake_get)

    service = DirectoryDiscoveryService()
    discovered = service.discover(["https://dir.example.test/top"], limit=10)

    assert len(discovered) == 1
    assert discovered[0].domain == "vendor-one.com"
    assert discovered[0].source_type == "directory"


def test_expansion_discovery_happy_path(monkeypatch):
    class DummyResponse:
        def __init__(self, text):
            self.text = text

        def raise_for_status(self):
            return None

    def fake_get(url, **kwargs):
        _ = kwargs
        if "partners" in url:
            return DummyResponse("<a href='https://retailer-x.com'>Retail Partners</a>")
        return DummyResponse("<html></html>")

    monkeypatch.setattr("lead_crawler.services.expansion_discovery_service.requests.get", fake_get)

    service = ExpansionDiscoveryService()
    discovered = service.discover(["seed-shop.com"], limit=10)

    assert len(discovered) == 1
    assert discovered[0].domain == "retailer-x.com"
    assert discovered[0].source_type == "expansion"
