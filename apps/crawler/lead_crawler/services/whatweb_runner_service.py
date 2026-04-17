"""WhatWeb runner service placeholder."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(slots=True)
class WhatWebResult:
    """Represents a normalized WhatWeb scan result."""

    target_url: str
    plugins: dict[str, Any] = field(default_factory=dict)


class WhatWebRunnerService:
    """Runs WhatWeb against URLs and normalizes the output."""

    def scan(self, target_url: str) -> WhatWebResult:
        """Run WhatWeb for a target URL and return parsed placeholder data."""
        return WhatWebResult(target_url=target_url)
