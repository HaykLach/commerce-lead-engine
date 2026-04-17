"""Laravel API client placeholder for crawler/backend communication."""

from __future__ import annotations

from typing import Any


class LaravelApiClient:
    """Provides typed methods for reporting crawler outputs to Laravel."""

    def __init__(self, base_url: str, api_token: str | None = None) -> None:
        """Initialize the client with API configuration."""
        self.base_url = base_url
        self.api_token = api_token

    def submit_lead_payload(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Submit a lead payload to the backend and return a placeholder response."""
        _ = payload
        return {"status": "not_implemented", "base_url": self.base_url}
