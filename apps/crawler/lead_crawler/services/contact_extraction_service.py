"""Collect contact candidates from downloaded HTML without making requests."""

from __future__ import annotations

import json
import re
from urllib.parse import unquote, urldefrag, urljoin, urlsplit

from bs4 import BeautifulSoup


class ContactExtractionService:
    """Evidence collection only: published contacts are not approved recipients.

    Limits apply per page. JavaScript-only and obfuscated contacts are left for
    later rendering/review. No form values, hidden tokens, or mailto query fields
    are collected, and no links or form actions are followed.
    """

    LOCAL_CHARS = r"A-Z0-9!#$%&'*+/=?^_`{|}~.-"
    EMAIL = re.compile(
        rf"(?<![\w{LOCAL_CHARS}])[{LOCAL_CHARS}]{{1,64}}@"
        r"(?:[A-Z0-9](?:[A-Z0-9-]{0,61}[A-Z0-9])?\.){1,125}[A-Z]{2,63}"
        r"(?![\w@-]|\.[A-Z0-9])",
        re.IGNORECASE,
    )
    CONTACT_LINK = re.compile(
        r"contact|kontakt|impressum|about|über.uns|ueber.uns|կապ|контакт",
        re.IGNORECASE,
    )
    OTHER_FORM = re.compile(
        r"newsletter|subscribe|login|sign.?in|password|checkout|review|rating|comment",
        re.IGNORECASE,
    )
    ORGANIZATION_TYPES = {"organization", "corporation", "localbusiness", "store", "onlinestore"}
    ASSET_SUFFIXES = {"png", "jpg", "jpeg", "gif", "svg", "webp", "css", "js", "woff", "woff2"}

    def __init__(self, max_candidates: int = 50, max_html_chars: int = 2_000_000) -> None:
        if max_candidates < 1 or max_html_chars < 1:
            raise ValueError("Extraction limits must be positive")
        self.max_candidates = max_candidates
        self.max_html_chars = max_html_chars

    def extract(self, html: str, page_url: str) -> dict:
        result = {
            "version": 1,
            "status": "collected",
            "emails": [],
            "forms": [],
            "contact_page_urls": [],
            "truncated": False,
        }
        source_url = self._web_url(page_url)
        if source_url is None:
            return {**result, "status": "skipped", "reason": "invalid_source_url"}
        if len(html) > self.max_html_chars:
            return {**result, "status": "skipped", "reason": "html_size_limit"}

        soup = BeautifulSoup(html, "html.parser")
        emails: dict[str, dict] = {}

        def add_email(value: str, method: str) -> None:
            value = value.strip()
            if not self.EMAIL.fullmatch(value):
                return
            local, domain = value.rsplit("@", 1)
            if len(value) > 254 or len(local) > 64 or local.startswith(".") or local.endswith(".") or ".." in local:
                return
            if domain.lower().rsplit(".", 1)[-1] in self.ASSET_SUFFIXES:
                return
            if domain.lower() in {"example.com", "example.net", "example.org"}:
                return
            key = value.lower()
            if key not in emails:
                if len(emails) >= self.max_candidates:
                    result["truncated"] = True
                    return
                emails[key] = {
                    "value": f"{local}@{domain.lower()}",
                    "source_url": source_url,
                    "source_methods": [],
                    "purpose_hint": self._purpose(local),
                    "domain_matches_page": self._host(domain) == self._host(urlsplit(source_url).hostname or ""),
                    "status": "candidate",
                }
            if method not in emails[key]["source_methods"]:
                emails[key]["source_methods"].append(method)

        # Read only organization/contact-point email properties from JSON-LD.
        # Arbitrary script text and Person/review records are not contact evidence.
        for script in soup.find_all("script", type=re.compile(r"^application/ld\+json$", re.I)):
            try:
                data = json.loads(script.get_text())
            except (ValueError, RecursionError):
                continue
            for value in self._organization_emails(data):
                add_email(value, "organization_jsonld")

        for node in soup.select("script, style, noscript, template, head, [hidden], [aria-hidden='true']"):
            node.decompose()

        contact_links: list[str] = []
        for link in soup.find_all("a", href=True):
            href = str(link["href"]).strip()
            if href.lower().startswith("mailto:"):
                # Only explicit To addresses; ignore subject/body/cc/bcc fields.
                for address in re.split(r"[,;]", unquote(href[7:].split("?", 1)[0])):
                    add_email(address, "mailto")
                continue
            url = self._web_url(urljoin(source_url, href))
            if url is None or self._host(urlsplit(url).hostname or "") != self._host(urlsplit(source_url).hostname or ""):
                continue
            label = f"{unquote(urlsplit(url).path)} {link.get_text(' ', strip=True)}"
            if self.CONTACT_LINK.search(label) and url not in contact_links:
                if len(contact_links) < self.max_candidates:
                    contact_links.append(url)
                else:
                    result["truncated"] = True

        for value in self.EMAIL.findall(soup.get_text(" ", strip=True)):
            add_email(value.rstrip("."), "html_text")

        for index, form in enumerate(soup.find_all("form")):
            fields = form.find_all(["input", "textarea", "select"])
            public_fields = [field for field in fields if str(field.get("type", "")).lower() not in {"hidden", "password"}]
            names = [str(field.get("name", ""))[:100] for field in public_fields if field.get("name")]
            context = " ".join([str(form.get("id", "")), str(form.get("class", "")), str(form.get("action", "")), *names])
            has_email = any(str(field.get("type", "")).lower() == "email" or "email" in str(field.get("name", "")).lower() for field in public_fields)
            if not has_email or form.find("textarea") is None or self.OTHER_FORM.search(context):
                continue
            if len(result["forms"]) >= self.max_candidates:
                result["truncated"] = True
                break
            result["forms"].append({
                "source_url": source_url,
                "form_index": index,
                "field_names": list(dict.fromkeys(names))[:50],
                "status": "candidate",
            })

        result["emails"] = list(emails.values())
        result["contact_page_urls"] = contact_links
        return result

    def _organization_emails(self, data: object):
        pending = [data]
        while pending:
            node = pending.pop()
            if isinstance(node, list):
                pending.extend(reversed(node))
            elif isinstance(node, dict):
                types = node.get("@type", [])
                if isinstance(types, str):
                    types = [types]
                if isinstance(types, list) and any(isinstance(value, str) and value.lower() in self.ORGANIZATION_TYPES for value in types):
                    contacts = node.get("contactPoint", [])
                    if isinstance(contacts, dict):
                        contacts = [contacts]
                    for contact in [node, *(contacts if isinstance(contacts, list) else [])]:
                        if isinstance(contact, dict) and isinstance(contact.get("email"), str):
                            value = contact["email"]
                            yield value[7:] if value.lower().startswith("mailto:") else value
                pending.extend(reversed(list(node.values())))

    @staticmethod
    def _web_url(value: str) -> str | None:
        try:
            parsed = urlsplit(value)
            if parsed.scheme.lower() not in {"http", "https"} or not parsed.hostname or parsed.username is not None or parsed.password is not None:
                return None
            if any(ord(char) < 32 for char in value) or len(value) > 2048:
                return None
            return urldefrag(value)[0]
        except ValueError:
            return None

    @staticmethod
    def _host(value: str) -> str:
        return value.lower().removeprefix("www.")

    @staticmethod
    def _purpose(local: str) -> str:
        words = set(re.split(r"[._+\-]", local.lower()))
        for purpose, hints in (
            ("legal_privacy", {"privacy", "legal", "dpo", "gdpr", "datenschutz"}),
            ("no_reply", {"noreply", "donotreply"}),
            ("recruitment", {"jobs", "careers", "hr"}),
            ("customer_support", {"support", "orders", "returns"}),
            ("business", {"info", "hello", "contact", "office", "sales", "partners", "partnerships"}),
        ):
            if words & hints or (purpose == "no_reply" and {"no", "reply"} <= words):
                return purpose
        return "unknown"
