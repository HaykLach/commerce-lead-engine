"""Heuristic fit filter for discovered domains.

Two-stage SME-focused filtering pipeline:

Stage 1 — Hard block
    Exact and subdomain denylist matches, blocked substring tokens,
    SLD length guard, and consecutive-digit spam guard.

Stage 2 — SME scoring  (soft signal, opt-in via min_sme_score > 0.0)
    :meth:`CommonCrawlDomainFilter.sme_score` returns a 0.0–1.0 score
    based on cheap structural / string signals on the domain name alone.
    :meth:`CommonCrawlDomainFilter.should_include` rejects domains whose
    score falls below *min_sme_score* (default 0.0 → behaviour unchanged).
"""

from __future__ import annotations

import re
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from lead_crawler.services.sme_tranco_filter import SmeTrancoFilter


class CommonCrawlDomainFilter:
    # ------------------------------------------------------------------
    # Default denylist — global giants + DACH / NL / SE enterprise brands
    # + platform storefronts that are never FFP clients
    # ------------------------------------------------------------------
    DEFAULT_DENYLIST: frozenset[str] = frozenset(
        {
            # ── Global marketplaces / platforms ──────────────────────────────
            "amazon.com",
            "ebay.com",
            "aliexpress.com",
            "etsy.com",
            "walmart.com",
            "target.com",
            "facebook.com",
            "instagram.com",
            "linkedin.com",
            "youtube.com",
            "x.com",
            "google.com",
            "microsoft.com",
            "apple.com",
            "cloudflare.com",
            "shopify.com",
            # ── DACH enterprise e-commerce ────────────────────────────────────
            "zalando.de",
            "otto.de",
            "mediamarkt.de",
            "saturn.de",
            "lidl.de",
            "aldi.de",
            "rewe.de",
            "dm.de",
            "douglas.de",
            "bonprix.de",
            "baur.de",
            "tchibo.de",
            "pearl.de",
            "notebooksbilliger.de",
            "cyberport.de",
            "alternate.de",
            "conrad.de",
            "galaxus.de",
            "digitec.ch",
            "interdiscount.ch",
            "microspot.ch",
            # ── NL / Benelux enterprise ───────────────────────────────────────
            "bol.com",
            "coolblue.nl",
            "bol.nl",
            "wehkamp.nl",
            # ── International fashion / home chains ───────────────────────────
            "hm.com",
            "ikea.com",
            "zara.com",
            # ── Platform storefronts (never direct FFP clients) ───────────────
            "myshopify.com",
            "squarespace.com",
            "wixsite.com",
            "webflow.io",
            "bigcartel.com",
        }
    )

    # Substring tokens that appear in enterprise / noise hostnames
    BLOCKED_SUBSTRING_TOKENS: tuple[str, ...] = (
        "marketplace",
        "wikipedia",
        "github",
        "corp",
        "holding",
        "gmbh-shop",
        "enterprise",
        "global",
        "international",
        "worldwide",
    )

    # ccTLDs that indicate DACH / target-region presence → scoring boost
    TARGET_CCTLDS: frozenset[str] = frozenset({"de", "nl", "ch", "at", "se", "ae"})

    # Words in the SLD that hint at an online shop
    COMMERCE_HINT_WORDS: frozenset[str] = frozenset(
        {"shop", "store", "handel", "markt", "laden", "boutique", "mode", "haus"}
    )

    # Words in the SLD that suggest enterprise / holding structures
    ENTERPRISE_HINT_WORDS: frozenset[str] = frozenset(
        {"holding", "group", "global", "international", "corp", "gmbh"}
    )

    # Regex that matches 3 or more consecutive decimal digits
    _CONSECUTIVE_DIGITS_RE = re.compile(r"\d{3,}")

    def __init__(
        self,
        denylist: set[str] | None = None,
        min_sme_score: float = 0.0,
        tranco_filter: "SmeTrancoFilter | None" = None,
    ) -> None:
        """
        Args:
            denylist: Extra domains to block on top of :attr:`DEFAULT_DENYLIST`.
            min_sme_score: Minimum :meth:`sme_score` required for a domain to
                pass.  ``0.0`` (the default) disables score-based filtering so
                existing behaviour is completely unchanged.
            tranco_filter: Optional
                :class:`~lead_crawler.services.sme_tranco_filter.SmeTrancoFilter`
                instance.  When provided, any domain in the Tranco top-N is
                blocked regardless of SME score.
        """
        self.denylist: set[str] = set(self.DEFAULT_DENYLIST)
        if denylist:
            self.denylist.update(d.lower() for d in denylist)

        self.min_sme_score = float(min_sme_score)
        self.tranco_filter = tranco_filter

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _get_sld(self, host: str) -> str:
        """Return the second-level domain label (the label before the TLD)."""
        labels = host.split(".")
        # "shop.brand.de" → ["shop","brand","de"] → SLD = "brand"
        # "brand.de"      → ["brand","de"]        → SLD = "brand"
        return labels[-2] if len(labels) >= 2 else host

    # ------------------------------------------------------------------
    # Public interface
    # ------------------------------------------------------------------

    def should_include(self, normalized_domain: str) -> bool:
        """Return *True* when the domain passes all filter stages.

        The interface (name, signature, return type) is intentionally kept
        identical to the original so callers and tests remain unaffected.
        """
        host = normalized_domain.lower()

        # ── Stage 1: Hard block ───────────────────────────────────────────────

        # Exact denylist match
        if host in self.denylist:
            return False

        # Subdomain of a denylisted domain  (e.g. "shop.zalando.de")
        if any(host.endswith(f".{blocked}") for blocked in self.denylist):
            return False

        # Blocked substring token anywhere in the hostname
        if any(token in host for token in self.BLOCKED_SUBSTRING_TOKENS):
            return False

        # Must have at least two labels (i.e. "domain.tld")
        labels = host.split(".")
        if len(labels) < 2:
            return False

        sld = self._get_sld(host)

        # SLD longer than 30 characters → likely typosquat / spam
        if len(sld) > 30:
            return False

        # 3+ consecutive digits in the SLD → parked / thin site signal
        if self._CONSECUTIVE_DIGITS_RE.search(sld):
            return False

        # Tranco top-N block
        if self.tranco_filter is not None and self.tranco_filter.is_large_site(host):
            return False

        # ── Stage 2: SME scoring (optional, default min=0.0 → no-op) ─────────
        if self.min_sme_score > 0.0:
            score = self.sme_score(host)
            if score < self.min_sme_score:
                return False

        return True

    def sme_score(self, domain: str) -> float:
        """Return a 0.0–1.0 SME-fit score for *domain*.

        All signals are cheap string / structural checks — no HTTP calls.
        The score is clamped to ``[0.0, 1.0]`` before returning.

        Scoring table
        -------------
        +0.20  ccTLD in target region (.de, .nl, .ch, .at, .se, .ae)
        +0.15  SLD length 4–20 chars
        +0.10  SLD contains a human/brand word (no digits, no consecutive hyphens)
        +0.10  Third-level domain present (e.g. shop.brand.de)
        +0.15  SLD contains a commerce hint word (shop, store, handel, …)
        −0.30  SLD contains an enterprise hint word (holding, group, …)
        −0.40  SLD is all-digits or >50 % numeric characters
        −1.00  Domain in denylist → instant 0.0
        """
        host = domain.lower()

        # Denylist members are always 0.0 (hard-block catches them first anyway)
        if host in self.denylist:
            return 0.0

        labels = host.split(".")
        if len(labels) < 2:
            return 0.0

        sld = self._get_sld(host)
        tld = labels[-1]

        score = 0.0

        # +0.20 — ccTLD matches a target region
        if tld in self.TARGET_CCTLDS:
            score += 0.2

        # +0.15 — SLD length in the typical SME brand range (4–20)
        if 4 <= len(sld) <= 20:
            score += 0.15

        # +0.10 — SLD looks like a human / brand word
        #          (no digit characters, no consecutive hyphens "--")
        if sld and not any(c.isdigit() for c in sld) and "--" not in sld:
            score += 0.1

        # +0.10 — Third-level (or deeper) subdomain present
        if len(labels) >= 3:
            score += 0.1

        # +0.15 — Commerce hint word in the SLD
        if any(hint in sld for hint in self.COMMERCE_HINT_WORDS):
            score += 0.15

        # −0.30 — Enterprise / holding hint word in the SLD
        if any(hint in sld for hint in self.ENTERPRISE_HINT_WORDS):
            score -= 0.3

        # −0.40 — SLD is all-digits or majority-digits (>50 % numeric chars)
        if sld:
            digit_ratio = sum(1 for c in sld if c.isdigit()) / len(sld)
            if digit_ratio > 0.5:
                score -= 0.4

        return max(0.0, min(1.0, score))
