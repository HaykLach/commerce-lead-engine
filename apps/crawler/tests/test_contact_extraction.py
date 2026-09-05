"""Contact extraction must retain evidence without choosing recipients or sending."""

import json

import pytest

from lead_crawler.services.contact_extraction_service import ContactExtractionService
from lead_crawler.services.page_classification_service import PageClassificationService


PAGE = "https://shop.test/contact"


def test_mailto_decoding_deduplication_and_query_fields():
    html = '''<a href="mailto:Info%40SHOP.TEST?cc=other@agency.test&amp;body=hidden@agency.test">Write us</a>
    <p>Contact info@shop.test.</p>'''

    result = ContactExtractionService().extract(html, PAGE)

    assert len(result["emails"]) == 1
    email = result["emails"][0]
    assert email["value"] == "Info@shop.test"
    assert email["source_url"] == PAGE
    assert email["source_methods"] == ["mailto", "html_text"]
    assert email["purpose_hint"] == "business"
    assert email["domain_matches_page"] is True
    assert email["status"] == "candidate"


def test_non_content_and_placeholder_addresses_are_excluded():
    html = '''<head><title>title@shop.test</title><script>const user = "script@shop.test";</script></head>
    <style>.background { background: url(logo@2x.png); }</style>
    <div hidden><a href="mailto:hidden@shop.test">Email</a></div>
    <template>template@shop.test</template><noscript>noscript@shop.test</noscript>
    <input type="hidden" value="token@shop.test">
    <p>support@example.com image@2x.png real@shop.test</p>'''

    result = ContactExtractionService().extract(html, PAGE)

    assert [email["value"] for email in result["emails"]] == ["real@shop.test"]


def test_jsonld_organization_and_contact_point_only():
    data = {"@graph": [
        {"@type": ["Organization", "Store"], "email": "hello@shop.test", "contactPoint": {"email": "sales@shop.test"}},
        {"@type": "Person", "email": "reviewer@customer.test"},
        {"@type": "Product", "description": "not-a-contact@customer.test"},
    ]}
    html = f'<script type="application/ld+json">{json.dumps(data)}</script>'
    html += '<script type="application/ld+json">invalid json</script>'

    result = ContactExtractionService().extract(html, PAGE)

    assert [email["value"] for email in result["emails"]] == ["hello@shop.test", "sales@shop.test"]
    assert all(email["source_methods"] == ["organization_jsonld"] for email in result["emails"])


@pytest.mark.parametrize("value,purpose", [
    ("privacy@shop.test", "legal_privacy"),
    ("no-reply@shop.test", "no_reply"),
    ("jobs@shop.test", "recruitment"),
    ("support@shop.test", "customer_support"),
    ("designer@agency.test", "unknown"),
])
def test_special_purpose_and_third_party_contacts_remain_candidates(value, purpose):
    result = ContactExtractionService().extract(f'<a href="mailto:{value}">Email</a>', PAGE)
    email = result["emails"][0]

    assert email["purpose_hint"] == purpose
    assert email["status"] == "candidate"
    assert email["domain_matches_page"] == value.endswith("@shop.test")
    assert "outreach_eligible" not in email


def test_contact_forms_do_not_expose_tokens_or_invent_email_addresses():
    html = '''<form id="contact-form" action="/submit?token=secret">
    <input type="hidden" name="csrf" value="secret">
    <input type="email" name="email"><textarea name="message"></textarea></form>
    <form id="newsletter"><input type="email" name="email"></form>
    <form id="review-form"><input type="email" name="email"><textarea name="review"></textarea></form>'''

    result = ContactExtractionService().extract(html, PAGE)

    assert result["emails"] == []
    assert result["forms"] == [{"source_url": PAGE, "form_index": 0, "field_names": ["email", "message"], "status": "candidate"}]
    assert "secret" not in json.dumps(result)
    assert "csrf" not in json.dumps(result)


def test_contact_links_resolve_relative_urls_and_exclude_other_hosts():
    html = '''<a href="/kontakt#team">Kontakt</a><a href="/kontakt">Contact</a>
    <a href="https://agency.test/contact">Contact agency</a><a href="javascript:contact()">Contact</a>
    <a href="https://user:password@shop.test/contact">Contact</a>
    <a href="/impressum">Impressum</a><a href="/products">Products</a>'''

    result = ContactExtractionService().extract(html, PAGE)

    assert result["contact_page_urls"] == ["https://shop.test/kontakt", "https://shop.test/impressum"]


def test_limits_do_not_silently_report_a_complete_empty_result():
    service = ContactExtractionService(max_candidates=1, max_html_chars=200)
    result = service.extract("<p>one@shop.test two@shop.test</p>", PAGE)
    assert len(result["emails"]) == 1
    assert result["truncated"] is True
    assert service.extract("x" * 201, PAGE)["reason"] == "html_size_limit"
    assert service.extract("<p>hello@shop.test</p>", "file:///tmp/page.html")["reason"] == "invalid_source_url"


def test_extraction_does_not_leak_contacts_between_pages():
    service = ContactExtractionService()
    service.extract("<p>one@shop.test</p>", PAGE)
    result = service.extract("<p>No contact listed</p>", "https://another.test/")
    assert result["emails"] == []
    assert result["forms"] == []


@pytest.mark.parametrize("invalid", [
    "a" * 65 + "@shop.test",
    "!" * 100_000 + "@shop.test",
    "hello@" + "a" * 64 + ".test",
])
def test_oversized_email_parts_do_not_produce_partial_candidates(invalid):
    result = ContactExtractionService().extract(f"<p>{invalid} hello@shop.test</p>", PAGE)

    assert [email["value"] for email in result["emails"]] == ["hello@shop.test"]


def test_classification_records_contact_evidence_from_existing_fetches(monkeypatch):
    service = PageClassificationService()
    homepage = "https://shop.test/"
    category = "https://shop.test/category"
    monkeypatch.setattr(service.fetcher, "fetch", lambda domain: {"html": '<a href="/category">Shop</a>', "final_url": homepage})
    calls = []

    def fetch_page(url):
        calls.append(url)
        return '<p>support@shop.test</p>', "https://shop.test/category/shoes"

    monkeypatch.setattr(service, "_fetch_page", fetch_page)
    monkeypatch.setattr(service, "_estimate_from_sitemap", lambda url: None)

    result = service.classify_domain("shop.test", max_pages=2)

    assert calls == [category]
    pages = result.classification_metadata["pages_scanned"]
    assert len(pages) == 2
    assert pages[1]["contacts"]["emails"][0]["source_url"] == "https://shop.test/category/shoes"
    assert pages[1]["final_url"] == "https://shop.test/category/shoes"
    json.dumps(result.classification_metadata)


def test_cross_host_redirect_does_not_attribute_another_business_contact(monkeypatch):
    service = PageClassificationService()
    monkeypatch.setattr(service.fetcher, "fetch", lambda domain: {"html": '<a href="/category">Shop</a>', "final_url": "https://shop.test/"})
    monkeypatch.setattr(service, "_fetch_page", lambda url: ("<p>sales@another.test</p>", "https://another.test/contact"))
    monkeypatch.setattr(service, "_estimate_from_sitemap", lambda url: None)

    result = service.classify_domain("shop.test", max_pages=2)

    assert len(result.classification_metadata["pages_scanned"]) == 1
    assert "sales@another.test" not in json.dumps(result.classification_metadata)
