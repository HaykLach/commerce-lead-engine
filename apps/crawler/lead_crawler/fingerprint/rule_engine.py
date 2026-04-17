"""Custom weighted fingerprint engine for ecommerce platform detection."""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Callable, Iterable

from lead_crawler.services.whatweb_runner_service import WhatWebResult

SignalMatcher = Callable[["FingerprintInputBundle"], list[str]]


@dataclass(slots=True)
class FingerprintInputBundle:
    """Input signals used by the fingerprint engine."""

    html: str = ""
    headers: dict[str, str] = field(default_factory=dict)
    cookies: dict[str, str] = field(default_factory=dict)
    script_urls: list[str] = field(default_factory=list)
    stylesheet_urls: list[str] = field(default_factory=list)
    links: list[str] = field(default_factory=list)
    whatweb: WhatWebResult | None = None


@dataclass(slots=True)
class FingerprintResult:
    """Normalized platform detection result."""

    platform: str
    confidence: float
    matched_signals: list[str] = field(default_factory=list)
    frontend_stack: list[str] = field(default_factory=list)


@dataclass(slots=True)
class FingerprintRule:
    """Detection rule with weighted signal matchers."""

    platform: str
    threshold: float
    signals: dict[str, tuple[float, SignalMatcher]]


class FingerprintRuleEngine:
    """Evaluates weighted rules against normalized fingerprint inputs."""

    def __init__(self, rules: list[FingerprintRule] | None = None) -> None:
        self._rules = rules or self._default_rules()

    def detect(self, inputs: FingerprintInputBundle) -> FingerprintResult:
        """Detect platform using weighted rule scoring."""
        best_platform = "unknown"
        best_confidence = 0.0
        best_signals: list[str] = []

        for rule in self._rules:
            score = 0.0
            matched: list[str] = []
            max_score = sum(weight for weight, _ in rule.signals.values()) or 1.0

            for signal_name, (weight, matcher) in rule.signals.items():
                values = matcher(inputs)
                if not values:
                    continue
                score += weight
                matched.extend(f"{signal_name}:{value}" for value in values)

            confidence = round(min(score / max_score, 1.0), 4)
            if confidence >= rule.threshold and confidence > best_confidence:
                best_platform = rule.platform
                best_confidence = confidence
                best_signals = matched

        frontend_stack = self._detect_frontend_stack(inputs)
        if best_platform == "unknown" and self._looks_custom_ecommerce(inputs):
            best_platform = "custom_ecommerce"
            best_confidence = max(best_confidence, 0.55)
            if not best_signals:
                best_signals = ["heuristic:ecommerce_navigation"]

        return FingerprintResult(
            platform=best_platform,
            confidence=round(best_confidence, 4),
            matched_signals=best_signals,
            frontend_stack=frontend_stack,
        )

    def detect_platform(self, signals: list[object]) -> str:
        """Backwards-compatible API that returns only platform name."""
        _ = signals
        return self.detect(FingerprintInputBundle()).platform

    @classmethod
    def _default_rules(cls) -> list[FingerprintRule]:
        return [
            FingerprintRule(
                platform="shopify",
                threshold=0.35,
                signals={
                    "html": (0.25, cls._match_html(r"shopify|cdn\.shopify\.com|shopify\.section")),
                    "headers": (0.2, cls._match_header(r"x-shopify|shopify")),
                    "cookies": (0.2, cls._match_cookie(r"_shopify|shopify")),
                    "scripts": (0.2, cls._match_script(r"shopify")),
                    "whatweb": (0.15, cls._match_whatweb(r"shopify")),
                },
            ),
            FingerprintRule(
                platform="woocommerce",
                threshold=0.35,
                signals={
                    "html": (0.2, cls._match_html(r"woocommerce|wp-content/plugins/woocommerce")),
                    "headers": (0.15, cls._match_header(r"x-wp|wordpress|woocommerce")),
                    "cookies": (0.25, cls._match_cookie(r"woocommerce|wordpress|wp_woocommerce")),
                    "scripts": (0.25, cls._match_script(r"woocommerce|wp-content")),
                    "whatweb": (0.15, cls._match_whatweb(r"woocommerce|wordpress")),
                },
            ),
            FingerprintRule(
                platform="prestashop",
                threshold=0.3,
                signals={
                    "html": (0.2, cls._match_html(r"prestashop")),
                    "headers": (0.1, cls._match_header(r"prestashop")),
                    "cookies": (0.3, cls._match_cookie(r"prestashop")),
                    "scripts": (0.25, cls._match_script(r"prestashop")),
                    "whatweb": (0.15, cls._match_whatweb(r"prestashop")),
                },
            ),
            FingerprintRule(
                platform="shopware",
                threshold=0.3,
                signals={
                    "html": (0.2, cls._match_html(r"shopware|data-shopware")),
                    "headers": (0.15, cls._match_header(r"shopware")),
                    "cookies": (0.2, cls._match_cookie(r"shopware")),
                    "scripts": (0.3, cls._match_script(r"shopware")),
                    "whatweb": (0.15, cls._match_whatweb(r"shopware")),
                },
            ),
        ]

    @staticmethod
    def _normalize_map(values: dict[str, str]) -> dict[str, str]:
        return {str(k).lower(): str(v).lower() for k, v in values.items()}

    @staticmethod
    def _contains_pattern(pattern: str, values: Iterable[str]) -> list[str]:
        regex = re.compile(pattern, re.IGNORECASE)
        return [value for value in values if regex.search(value)]

    @classmethod
    def _match_html(cls, pattern: str) -> SignalMatcher:
        def _matcher(inputs: FingerprintInputBundle) -> list[str]:
            return cls._contains_pattern(pattern, [inputs.html.lower()])

        return _matcher

    @classmethod
    def _match_header(cls, pattern: str) -> SignalMatcher:
        def _matcher(inputs: FingerprintInputBundle) -> list[str]:
            headers = cls._normalize_map(inputs.headers)
            joined = [f"{name}:{value}" for name, value in headers.items()]
            return cls._contains_pattern(pattern, joined)

        return _matcher

    @classmethod
    def _match_cookie(cls, pattern: str) -> SignalMatcher:
        def _matcher(inputs: FingerprintInputBundle) -> list[str]:
            cookies = cls._normalize_map(inputs.cookies)
            joined = [f"{name}:{value}" for name, value in cookies.items()]
            return cls._contains_pattern(pattern, joined)

        return _matcher

    @classmethod
    def _match_script(cls, pattern: str) -> SignalMatcher:
        def _matcher(inputs: FingerprintInputBundle) -> list[str]:
            values = [*inputs.script_urls, *inputs.stylesheet_urls]
            return cls._contains_pattern(pattern, [value.lower() for value in values])

        return _matcher

    @classmethod
    def _match_whatweb(cls, pattern: str) -> SignalMatcher:
        def _matcher(inputs: FingerprintInputBundle) -> list[str]:
            plugins = inputs.whatweb.plugins if inputs.whatweb else {}
            return cls._contains_pattern(pattern, plugins.keys())

        return _matcher

    @classmethod
    def _detect_frontend_stack(cls, inputs: FingerprintInputBundle) -> list[str]:
        haystack = "\n".join(
            [
                inputs.html,
                *inputs.script_urls,
                *inputs.stylesheet_urls,
            ]
        ).lower()

        stack_map = {
            "react": r"react|_next/static|next\.js|nextjs",
            "vue": r"vue(?:\.js)?|nuxt",
            "angular": r"angular|ng-",
            "jquery": r"jquery",
            "bootstrap": r"bootstrap",
            "tailwind": r"tailwind",
            "alpine": r"alpine(?:\.js)?",
        }

        detected = [name for name, pattern in stack_map.items() if re.search(pattern, haystack)]
        return sorted(detected)

    @classmethod
    def _looks_custom_ecommerce(cls, inputs: FingerprintInputBundle) -> bool:
        haystack = "\n".join([inputs.html, *inputs.links]).lower()
        keywords = ["/cart", "/checkout", "/product", "add to cart", "shop now"]
        matches = sum(1 for keyword in keywords if keyword in haystack)
        return matches >= 2
