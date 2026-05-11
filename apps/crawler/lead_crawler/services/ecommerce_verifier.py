"""Site quality filter — rejects only explicitly uninteresting pages.

Strategy: accept by default, disqualify only when a site clearly belongs
to one of three unwanted categories:

1. Wiki / encyclopaedia sites  (Wikipedia-style, MediaWiki, etc.)
2. Pure-blog / content sites that have no checkout or product flow whatsoever
3. Adult / 18+ content sites

Everything else — corporate pages, SaaS landing pages, informational sites
that might also sell — passes through for platform fingerprinting.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from enum import Enum


class DisqualificationReason(str, Enum):
    DOMAIN_TOKEN = "domain_token"
    WIKI = "wiki"
    BLOG_WITHOUT_CHECKOUT = "blog_without_checkout"
    ADULT_CONTENT = "adult_content"


@dataclass
class SiteFilterResult:
    is_acceptable: bool
    reason: DisqualificationReason | None = None
    signals: list[str] = field(default_factory=list)
    disqualifiers: list[str] = field(default_factory=list)


class EcommerceVerifier:
    """Filter out sites that are clearly uninteresting for lead generation.

    Accepts the vast majority of pages; rejects only:
    - Wiki/encyclopaedia sites
    - Blogs / content sites with no checkout or product signals
    - Adult / 18+ content sites
    """

    # ── Hard domain-token disqualifiers ──────────────────────────────────────
    # If ANY of these substrings appear in the domain, reject immediately.
    DISQUALIFIER_DOMAIN_TOKENS: frozenset[str] = frozenset(
        {
            # Encyclopaedia / reference
            "wikipedia", "wikimedia", "mediawiki", "wikidata",
            # Developer platforms (never leads)
            "github", "gitlab", "bitbucket", "stackoverflow", "stackexchange",
        }
    )

    # Adult / 18+ terms in the domain name
    ADULT_DOMAIN_TOKENS: frozenset[str] = frozenset(
        {
            "porn", "pornhub", "xvideos", "xnxx", "youporn", "redtube",
            "sex", "sexfilm", "sexchat", "livesex",
            "nude", "nudist", "nudemodel",
            "erotic", "erotik",           # German: erotic
            "hentai", "fetish",
            "onlyfans", "stripper", "escort",
            "camgirl", "camboy", "webcam-sex",
            "xxx", "18only", "18plus",
        }
    )

    # ── Wiki / encyclopaedia content signals ─────────────────────────────────
    WIKI_PATTERNS: list[str] = [
        r"mediawiki",
        r"Special:[A-Z][A-Za-z]+",       # MediaWiki special pages
        r"action=edit(?:&|$)",            # MediaWiki edit links
        r"wiki(?:pedia|source|quote|books|voyage|news|versity|data)",
        r'id=["\']?mw-',                  # MediaWiki HTML id attributes
        r"wikimedia\.org",
        r"<title>[^<]*(?:wiki|Wikipedia)",
    ]

    # ── Checkout / store presence signals ────────────────────────────────────
    # Any of these means the site has at least some transactional intent.
    CHECKOUT_SIGNALS: list[str] = [
        r"/cart\b",
        r"/checkout\b",
        r"/warenkorb\b",                  # German: shopping cart
        r"/kasse\b",                      # German: checkout
        r"/basket\b",
        r"/panier\b",                     # French: basket
        r"add[_\-\s]?to[_\-\s]?cart",
        r"in[_\-\s]?den[_\-\s]?warenkorb",
        r"add[_\-\s]?to[_\-\s]?basket",
        r"in[_\-\s]?den[_\-\s]?einkaufswagen",
        r"/product[s]?/",
        r"/produkt[e]?/",
        r"/shop/",
        r"/store/",
        r"/artikel/\d+",                  # German shop article ID path
        r"\bSKU\b",
        r"buy[\s\-]?now",
        r"jetzt[\s\-]?kaufen",            # German: buy now
        r"auf[\s\-]?lager",              # German: in stock
        r"preis.*€|€.*preis",            # German price + currency
        # Known ecommerce platform fingerprints
        r"shopify", r"woocommerce", r"prestashop", r"shopware",
        r"magento", r"bigcommerce", r"opencart", r"oxid",
        r"gambio", r"jtlshop", r"ecwid",
    ]

    # ── Blog / content-only signals ──────────────────────────────────────────
    # We only use these when NO checkout signal is present.
    BLOG_SIGNALS: list[str] = [
        r"/blog/",
        r"/news/",
        r"/beitrag/",                     # German: post/article
        r"/nachrichten/",                 # German: news
        r"/ratgeber/",                    # German: advice/guide
        r"/author/",
        r"/category/(?![\d])",            # /category/ not followed by a digit
        r"/tag/",
        r"wp-content/uploads",            # WordPress uploads without WooCommerce
        r'type=["\']?application/rss\+xml',
        r"<rss\b",
        r"powered by wordpress",
    ]

    # ── Adult content signals in page body ───────────────────────────────────
    ADULT_CONTENT_PATTERNS: list[str] = [
        # Age gate copy (multiple languages)
        r"you must be 18",
        r"must be (at least )?18 years",
        r"18\+\s*(only|content|website|site)",
        r"adult\s+content\s+(ahead|warning|only)",
        r"enter only if you are 18",
        r"sie m.ssen\s+18\s+jahre",       # German: you must be 18 years
        r"nur f.r\s+volljährige",         # German: adults only
        r"bist du\s+(über|mindestens)\s+18",   # German: are you over 18
        # Common adult meta / markup
        r'content=["\']?adult["\']?',
        r'rating=["\']?adult["\']?',
        r'og:type["\']?\s*content=["\']?adult',
        # Platform watermarks
        r"onlyfans\.com",
        r"pornhub\.com",
        r"xvideos\.com",
    ]

    # A blog is disqualified only when it has this many blog signals AND zero checkout signals.
    BLOG_SIGNAL_THRESHOLD: int = 2

    def verify(
        self,
        domain: str,
        html: str,
        links: list[str],
        scripts: list[str],
    ) -> SiteFilterResult:
        """Return a SiteFilterResult indicating whether the site is acceptable.

        Args:
            domain: Normalized domain name (no scheme or trailing slash).
            html: Raw homepage HTML.
            links: Absolute link hrefs extracted from the page.
            scripts: Script src URLs extracted from the page.
        """
        domain_lower = (domain or "").lower()

        # ── Stage 1: hard domain-token checks ────────────────────────────────

        for token in self.DISQUALIFIER_DOMAIN_TOKENS:
            if token in domain_lower:
                return SiteFilterResult(
                    is_acceptable=False,
                    reason=DisqualificationReason.DOMAIN_TOKEN,
                    disqualifiers=[f"domain_token:{token}"],
                )

        for token in self.ADULT_DOMAIN_TOKENS:
            if token in domain_lower:
                return SiteFilterResult(
                    is_acceptable=False,
                    reason=DisqualificationReason.ADULT_CONTENT,
                    disqualifiers=[f"adult_domain_token:{token}"],
                )

        haystack = "\n".join(filter(None, [html, *links, *scripts])).lower()

        # ── Stage 2: adult content in page body ──────────────────────────────

        adult_hits = self._match_patterns(haystack, self.ADULT_CONTENT_PATTERNS)
        if adult_hits:
            return SiteFilterResult(
                is_acceptable=False,
                reason=DisqualificationReason.ADULT_CONTENT,
                disqualifiers=[f"adult_page:{p}" for p in adult_hits[:3]],
            )

        # ── Stage 3: wiki / encyclopaedia detection ───────────────────────────

        wiki_hits = self._match_patterns(haystack, self.WIKI_PATTERNS)
        if wiki_hits:
            return SiteFilterResult(
                is_acceptable=False,
                reason=DisqualificationReason.WIKI,
                disqualifiers=[f"wiki:{p}" for p in wiki_hits[:3]],
            )

        # ── Stage 4: blog-without-checkout detection ──────────────────────────
        # Only reject when the site looks like a pure content/blog site AND has
        # zero transactional (checkout/product/store) signals.

        checkout_hits = self._match_patterns(haystack, self.CHECKOUT_SIGNALS)
        blog_hits = self._match_patterns(haystack, self.BLOG_SIGNALS)

        has_any_checkout = len(checkout_hits) > 0
        is_blog_only = len(blog_hits) >= self.BLOG_SIGNAL_THRESHOLD and not has_any_checkout

        if is_blog_only:
            return SiteFilterResult(
                is_acceptable=False,
                reason=DisqualificationReason.BLOG_WITHOUT_CHECKOUT,
                disqualifiers=[f"blog:{p}" for p in blog_hits[:3]],
                signals=[],
            )

        # ── Accepted ─────────────────────────────────────────────────────────

        signals: list[str] = [f"checkout:{p}" for p in checkout_hits[:5]]

        return SiteFilterResult(
            is_acceptable=True,
            reason=None,
            signals=signals,
            disqualifiers=[],
        )

    @staticmethod
    def _match_patterns(haystack: str, patterns: list[str]) -> list[str]:
        """Return the subset of *patterns* that match *haystack*."""
        return [
            pattern
            for pattern in patterns
            if re.search(pattern, haystack, re.IGNORECASE)
        ]
