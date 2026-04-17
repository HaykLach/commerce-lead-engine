"""Item definitions for crawler outputs."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


@dataclass(slots=True)
class LeadPageItem:
    """Represents a crawled page and associated metadata used for lead analysis."""

    url: str
    domain: str
    status_code: int | None = None
    metadata: dict[str, Any] = field(default_factory=dict)
