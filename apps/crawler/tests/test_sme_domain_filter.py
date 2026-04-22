"""Tests for the extended CommonCrawlDomainFilter (SME scoring) and SmeTrancoFilter."""

from __future__ import annotations

import io
import sys
import zipfile
from types import ModuleType
from unittest.mock import Mock, patch, MagicMock

# ---------------------------------------------------------------------------
# Stub heavy optional dependencies before any project import
# ---------------------------------------------------------------------------
sys.modules.setdefault("bs4", Mock())

import types as _types

_requests_stub = _types.ModuleType("requests")


class _RequestException(IOError):
    pass


_requests_stub.RequestException = _RequestException  # type: ignore[attr-defined]
_requests_stub.get = Mock()  # type: ignore[attr-defined]
sys.modules.setdefault("requests", _requests_stub)

from lead_crawler.services.common_crawl_domain_filter import CommonCrawlDomainFilter  # noqa: E402
from lead_crawler.services.sme_tranco_filter import SmeTrancoFilter  # noqa: E402


# ===========================================================================
# CommonCrawlDomainFilter — Stage 1: Hard block
# ===========================================================================


class TestHardBlockExpanded:
    """Expanded denylist covers DACH, NL, and global giants."""

    def test_dach_giants_blocked(self):
        f = CommonCrawlDomainFilter()
        for domain in (
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
        ):
            assert not f.should_include(domain), f"{domain} should be blocked"

    def test_nl_enterprise_blocked(self):
        f = CommonCrawlDomainFilter()
        for domain in ("bol.com", "coolblue.nl", "bol.nl", "wehkamp.nl"):
            assert not f.should_include(domain), f"{domain} should be blocked"

    def test_global_giants_blocked(self):
        f = CommonCrawlDomainFilter()
        for domain in ("amazon.com", "ebay.com", "shopify.com", "hm.com", "ikea.com", "zara.com"):
            assert not f.should_include(domain), f"{domain} should be blocked"

    def test_platform_storefronts_blocked(self):
        f = CommonCrawlDomainFilter()
        for domain in (
            "myshopify.com",
            "squarespace.com",
            "wixsite.com",
            "webflow.io",
            "bigcartel.com",
        ):
            assert not f.should_include(domain), f"{domain} should be blocked"

    def test_legitimate_sme_passes(self):
        f = CommonCrawlDomainFilter()
        assert f.should_include("tiny-shop.de") is True
        assert f.should_include("boutique-berlin.de") is True


class TestSldLengthGuard:
    def test_sld_longer_than_30_blocked(self):
        long_sld = "a" * 31
        domain = f"{long_sld}.de"
        f = CommonCrawlDomainFilter()
        assert not f.should_include(domain)

    def test_sld_exactly_30_allowed(self):
        sld = "a" * 30
        domain = f"{sld}.de"
        f = CommonCrawlDomainFilter()
        # 30 chars is at the limit — should pass the length guard
        assert f.should_include(domain) is True

    def test_normal_sld_length_passes(self):
        f = CommonCrawlDomainFilter()
        assert f.should_include("myshop.de") is True


class TestConsecutiveDigitGuard:
    def test_three_consecutive_digits_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("shop123456.de")

    def test_two_digits_allowed(self):
        f = CommonCrawlDomainFilter()
        assert f.should_include("shop12.de") is True

    def test_non_consecutive_digits_allowed(self):
        f = CommonCrawlDomainFilter()
        # "a1b2c" — no 3+ consecutive run
        assert f.should_include("a1b2c.de") is True


class TestSubdomainOfDenylisted:
    def test_subdomain_of_zalando_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("shop.zalando.de")

    def test_subdomain_of_amazon_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("www.amazon.com")

    def test_subdomain_of_coolblue_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("outlet.coolblue.nl")


class TestBlockedSubstringTokens:
    def test_corp_token_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("bigcorp.de")

    def test_holding_token_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("musterholding.de")

    def test_enterprise_token_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("enterprise-solutions.de")

    def test_gmbh_shop_token_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("gmbh-shop.de")

    def test_international_token_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("brand-international.de")

    def test_worldwide_token_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("brandworldwide.de")

    def test_global_token_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("global-shop.de")

    def test_marketplace_token_blocked(self):
        f = CommonCrawlDomainFilter()
        assert not f.should_include("mymarketplace.de")

    def test_clean_domain_not_blocked(self):
        f = CommonCrawlDomainFilter()
        assert f.should_include("mode-berlin.de") is True


# ===========================================================================
# CommonCrawlDomainFilter — Stage 2: SME scoring
# ===========================================================================


class TestSmeScoreRange:
    def test_score_is_float_in_range(self):
        f = CommonCrawlDomainFilter()
        for domain in (
            "tiny-shop.de",
            "boutique.nl",
            "mystore.com",
            "12345.de",
            "holding-group.de",
            "a.b.shop.de",
        ):
            score = f.sme_score(domain)
            assert isinstance(score, float), f"score for {domain} is not float"
            assert 0.0 <= score <= 1.0, f"score {score} out of range for {domain}"

    def test_denylist_domain_scores_zero(self):
        f = CommonCrawlDomainFilter()
        assert f.sme_score("zalando.de") == 0.0
        assert f.sme_score("amazon.com") == 0.0


class TestCcTldBoost:
    def test_target_cctld_boosts_score(self):
        f = CommonCrawlDomainFilter()
        score_de = f.sme_score("myshop.de")
        score_com = f.sme_score("myshop.com")
        # .de should outscore .com by the ccTLD boost
        assert score_de > score_com

    def test_all_target_tlds_boosted(self):
        f = CommonCrawlDomainFilter()
        base_score = f.sme_score("myshop.io")  # non-target TLD
        for tld in ("de", "nl", "ch", "at", "se", "ae"):
            boosted = f.sme_score(f"myshop.{tld}")
            assert boosted > base_score, f".{tld} should boost score above .io"


class TestCommerceHintBoost:
    def test_shop_in_sld_boosts(self):
        f = CommonCrawlDomainFilter()
        # "myshop" contains "shop"
        score_with_hint = f.sme_score("myshop.de")
        score_without = f.sme_score("myplace.de")
        assert score_with_hint > score_without

    def test_commerce_words_boost(self):
        f = CommonCrawlDomainFilter()
        baseline = f.sme_score("myplace.de")
        for word in ("shop", "store", "handel", "markt", "laden", "boutique", "mode", "haus"):
            score = f.sme_score(f"my{word}.de")
            assert score >= baseline, f"'{word}' in SLD should not reduce score"


class TestEnterpriseHintPenalty:
    def test_holding_in_sld_penalises(self):
        f = CommonCrawlDomainFilter()
        score_clean = f.sme_score("myshop.de")
        score_holding = f.sme_score("myholding.de")
        assert score_holding < score_clean

    def test_enterprise_words_penalise(self):
        f = CommonCrawlDomainFilter()
        baseline = f.sme_score("myshop.de")
        for word in ("holding", "group", "global", "international", "corp", "gmbh"):
            score = f.sme_score(f"my{word}.de")
            assert score < baseline, f"'{word}' in SLD should reduce score below baseline"


class TestMinSmeScoreThreshold:
    def test_borderline_domain_blocked_when_threshold_set(self):
        """A domain that would pass at 0.0 can be blocked by a higher threshold.

        We need a domain that:
        - Clears all Stage-1 hard-block rules (no denylist hit, no blocked token,
          SLD ≤ 30 chars, no 3+ consecutive digits)
        - Has a low enough SME score to sit below a 0.5 threshold

        "xbrand.io" fits:  SLD "xbrand" (6 chars, letters only) on a non-target TLD.
        Expected score ≈ 0.25  (+0.15 SLD-length + 0.10 brand-word; no ccTLD boost).
        """
        domain = "xbrand.io"  # non-target TLD, no commerce/enterprise hints
        f_permissive = CommonCrawlDomainFilter(min_sme_score=0.0)
        f_strict = CommonCrawlDomainFilter(min_sme_score=0.5)

        score = f_permissive.sme_score(domain)
        # Verify the domain truly passes stage-1 and scores below the threshold
        assert score < 0.5, f"Expected score < 0.5, got {score}"
        assert f_permissive.should_include(domain) is True
        assert f_strict.should_include(domain) is False

    def test_good_sme_domain_passes_moderate_threshold(self):
        domain = "tiny-shop.de"
        f = CommonCrawlDomainFilter(min_sme_score=0.2)
        assert f.should_include(domain) is True

    def test_default_threshold_zero_is_no_op(self):
        """min_sme_score=0.0 must not change the Stage-1-only behaviour."""
        f_old = CommonCrawlDomainFilter()
        f_new = CommonCrawlDomainFilter(min_sme_score=0.0)
        for domain in ("tiny-shop.de", "boutique-berlin.de", "shop-alpha.nl"):
            assert f_old.should_include(domain) == f_new.should_include(domain)


# ===========================================================================
# SmeTrancoFilter
# ===========================================================================

def _make_zip_csv(rows: list[tuple[int, str]]) -> bytes:
    """Build an in-memory ZIP containing top-1m.csv with the given rows."""
    csv_lines = "\n".join(f"{rank},{domain}" for rank, domain in rows)
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        zf.writestr("top-1m.csv", csv_lines)
    return buf.getvalue()


def _mock_requests_get(zip_bytes: bytes):
    resp = Mock()
    resp.status_code = 200
    resp.content = zip_bytes
    resp.raise_for_status = Mock()
    return resp


class TestSmeTrancoFilterBlocking:
    def _filter_with_rows(self, rows, top_n=100):
        f = SmeTrancoFilter(top_n=top_n)
        zip_bytes = _make_zip_csv(rows)
        with patch(
            "lead_crawler.services.sme_tranco_filter.requests"
        ) as mock_req:
            mock_req.get.return_value = _mock_requests_get(zip_bytes)
            # Trigger load
            result = f.is_large_site("zalando.de")
        return f

    def test_domain_in_top_n_is_blocked(self):
        rows = [(1, "zalando.de"), (2, "otto.de"), (3, "tiny-shop.de")]
        f = SmeTrancoFilter(top_n=2)
        zip_bytes = _make_zip_csv(rows)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            mock_req.get.return_value = _mock_requests_get(zip_bytes)

            assert f.is_large_site("zalando.de") is True
            assert f.is_large_site("otto.de") is True
            # tiny-shop.de is rank 3 but top_n=2 → should NOT be loaded
            assert f.is_large_site("tiny-shop.de") is False

    def test_domain_outside_top_n_is_not_blocked(self):
        rows = [(1, "zalando.de"), (2, "otto.de"), (3, "tiny-shop.de")]
        f = SmeTrancoFilter(top_n=1)
        zip_bytes = _make_zip_csv(rows)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            mock_req.get.return_value = _mock_requests_get(zip_bytes)

            # Only rank-1 should be loaded
            assert f.is_large_site("zalando.de") is True
            assert f.is_large_site("otto.de") is False

    def test_subdomain_of_top_n_is_blocked(self):
        rows = [(1, "zalando.de")]
        f = SmeTrancoFilter(top_n=10)
        zip_bytes = _make_zip_csv(rows)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            mock_req.get.return_value = _mock_requests_get(zip_bytes)

            # "www.zalando.de" is a subdomain of "zalando.de"
            assert f.is_large_site("www.zalando.de") is True
            # "shop.brand.zalando.de" is also a descendant
            assert f.is_large_site("shop.brand.zalando.de") is True

    def test_unrelated_domain_not_blocked(self):
        rows = [(1, "zalando.de"), (2, "otto.de")]
        f = SmeTrancoFilter(top_n=10)
        zip_bytes = _make_zip_csv(rows)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            mock_req.get.return_value = _mock_requests_get(zip_bytes)

            assert f.is_large_site("tiny-boutique.de") is False


class TestSmeTrancoFilterDownloadFailure:
    def test_download_failure_returns_false(self):
        """When the download fails, is_large_site returns False and does not raise."""
        f = SmeTrancoFilter(top_n=50_000)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            mock_req.get.side_effect = _RequestException("no internet")

            result = f.is_large_site("zalando.de")

        assert result is False  # fail open

    def test_download_failure_does_not_crash_pipeline(self):
        f = SmeTrancoFilter(top_n=50_000)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            mock_req.get.side_effect = _RequestException("timeout")

            # Must not raise
            for domain in ("zalando.de", "tiny-shop.de", "otto.de"):
                result = f.is_large_site(domain)
                assert isinstance(result, bool)


class TestSmeTrancoFilterDiskCache:
    def test_cache_path_is_written_after_download(self, tmp_path):
        rows = [(1, "zalando.de"), (2, "otto.de")]
        cache_file = str(tmp_path / "tranco.csv")
        f = SmeTrancoFilter(top_n=10, cache_path=cache_file)
        zip_bytes = _make_zip_csv(rows)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            mock_req.get.return_value = _mock_requests_get(zip_bytes)
            f.is_large_site("zalando.de")

        import os
        assert os.path.exists(cache_file)

    def test_cache_path_avoids_re_download(self, tmp_path):
        rows = [(1, "zalando.de")]
        cache_file = str(tmp_path / "tranco.csv")

        # Write the CSV directly to simulate a pre-existing cache
        with open(cache_file, "w") as fh:
            fh.write("1,zalando.de\n")

        f = SmeTrancoFilter(top_n=10, cache_path=cache_file)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            result = f.is_large_site("zalando.de")
            # requests.get should NOT have been called
            mock_req.get.assert_not_called()

        assert result is True


# ===========================================================================
# SmeTrancoFilter wired into CommonCrawlDomainFilter
# ===========================================================================


class TestTrancoWiredIntoDomainFilter:
    def _build_filter_with_tranco(self, domains_in_list: list[str]):
        """Helper: build a filter where the given domains are in the Tranco top-N."""
        rows = list(enumerate(domains_in_list, start=1))
        zip_bytes = _make_zip_csv(rows)
        tranco = SmeTrancoFilter(top_n=len(domains_in_list) + 10)

        with patch("lead_crawler.services.sme_tranco_filter.requests") as mock_req:
            mock_req.get.return_value = _mock_requests_get(zip_bytes)
            # Pre-load the list
            tranco._load()

        return CommonCrawlDomainFilter(tranco_filter=tranco)

    def test_tranco_large_site_blocked_by_domain_filter(self):
        f = self._build_filter_with_tranco(["big-shop.de"])
        assert f.should_include("big-shop.de") is False

    def test_tranco_subdomain_blocked_by_domain_filter(self):
        f = self._build_filter_with_tranco(["big-shop.de"])
        assert f.should_include("outlet.big-shop.de") is False

    def test_unrelated_domain_not_affected_by_tranco(self):
        f = self._build_filter_with_tranco(["big-shop.de"])
        assert f.should_include("tiny-boutique.de") is True

    def test_tranco_none_leaves_filter_unchanged(self):
        """Passing tranco_filter=None must not break the filter."""
        f = CommonCrawlDomainFilter(tranco_filter=None)
        assert f.should_include("tiny-shop.de") is True
        assert f.should_include("zalando.de") is False
