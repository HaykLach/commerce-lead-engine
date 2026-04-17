"""Service for running WhatWeb scans and normalizing output."""

from __future__ import annotations

import json
import subprocess
from dataclasses import dataclass, field
from typing import Any, Callable, Sequence


@dataclass(slots=True)
class WhatWebResult:
    """Represents a normalized WhatWeb scan result."""

    target_url: str
    plugins: dict[str, dict[str, Any]] = field(default_factory=dict)
    raw_payload: dict[str, Any] | None = None
    error: str | None = None


class WhatWebRunnerService:
    """Runs WhatWeb against URLs and returns normalized result payloads."""

    def __init__(
        self,
        command: Sequence[str] | None = None,
        runner: Callable[[list[str]], subprocess.CompletedProcess[str]] | None = None,
    ) -> None:
        self._command = list(command or ["whatweb", "--log-json=-", "--quiet"])
        self._runner = runner or self._default_runner

    def scan(self, target_url: str) -> WhatWebResult:
        """Run WhatWeb for a target URL and return normalized data."""
        cmd = [*self._command, target_url]

        try:
            completed = self._runner(cmd)
        except FileNotFoundError:
            return WhatWebResult(
                target_url=target_url,
                error="whatweb_binary_missing",
            )

        if completed.returncode != 0:
            return WhatWebResult(
                target_url=target_url,
                error=(completed.stderr or "whatweb_scan_failed").strip(),
            )

        payload = self._parse_output(completed.stdout)
        if payload is None:
            return WhatWebResult(target_url=target_url, error="whatweb_parse_failed")

        return WhatWebResult(
            target_url=payload.get("target") or target_url,
            plugins=self._normalize_plugins(payload.get("plugins")),
            raw_payload=payload,
        )

    @staticmethod
    def _default_runner(cmd: list[str]) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            cmd,
            check=False,
            capture_output=True,
            text=True,
        )

    @staticmethod
    def _parse_output(stdout: str) -> dict[str, Any] | None:
        for line in stdout.splitlines():
            line = line.strip()
            if not line:
                continue
            try:
                parsed = json.loads(line)
            except json.JSONDecodeError:
                continue

            if isinstance(parsed, list) and parsed:
                first = parsed[0]
                if isinstance(first, dict):
                    return first
            if isinstance(parsed, dict):
                return parsed

        return None

    @staticmethod
    def _normalize_plugins(raw_plugins: Any) -> dict[str, dict[str, Any]]:
        if not isinstance(raw_plugins, dict):
            return {}

        normalized: dict[str, dict[str, Any]] = {}
        for plugin_name, payload in raw_plugins.items():
            plugin_key = str(plugin_name).strip().lower()
            if not plugin_key:
                continue

            if isinstance(payload, dict):
                normalized[plugin_key] = payload
            elif isinstance(payload, list):
                normalized[plugin_key] = {"values": payload}
            else:
                normalized[plugin_key] = {"value": payload}

        return normalized
