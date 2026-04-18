"""Domain normalization helpers for discovery flows."""

from __future__ import annotations

import ipaddress
import re
from urllib.parse import urlparse


class DomainNormalizer:
    """Normalizes and validates candidate domains."""

    INVALID_HOST_TOKENS = {
        "localhost",
        "test",
        "invalid",
        "local",
    }

    def normalize(self, value: str | None) -> str | None:
        candidate = (value or "").strip().lower()
        if not candidate:
            return None
        if candidate.startswith(("mailto:", "tel:", "javascript:", "#")):
            return None

        parsed = urlparse(candidate if "://" in candidate else f"https://{candidate}")
        host = (parsed.netloc or parsed.path).strip().lower().strip("/")

        if "@" in host:
            host = host.split("@", 1)[-1]

        host = host.split(":", 1)[0].strip(".")

        if host.startswith("www."):
            host = host[4:]

        if not self._is_valid_host(host):
            return None

        return host

    def _is_valid_host(self, host: str) -> bool:
        if not host or "." not in host:
            return False

        if host in self.INVALID_HOST_TOKENS:
            return False

        if " " in host or "_" in host:
            return False

        if host.endswith(".local"):
            return False

        if re.search(r"[^a-z0-9.-]", host):
            return False

        try:
            ipaddress.ip_address(host)
            return False
        except ValueError:
            pass

        labels = host.split(".")
        if any(not label or label.startswith("-") or label.endswith("-") for label in labels):
            return False

        if len(labels[-1]) < 2:
            return False

        return True
