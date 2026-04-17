"""Fingerprint rule engine placeholders."""

from __future__ import annotations

from dataclasses import dataclass


@dataclass(slots=True)
class FingerprintSignal:
    """Represents a fingerprinting signal extracted from page or scan data."""

    name: str
    value: str
    confidence: float


class FingerprintRuleEngine:
    """Evaluates configured fingerprint rules against extracted signals."""

    def detect_platform(self, signals: list[FingerprintSignal]) -> str:
        """Return a placeholder platform label based on rule evaluation."""
        _ = signals
        return "unknown"
