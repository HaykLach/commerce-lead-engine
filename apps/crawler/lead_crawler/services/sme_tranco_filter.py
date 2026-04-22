"""Tranco top-N blocklist for large-site filtering.

Downloads the Tranco top-1 M list on first use, caches it in memory and
optionally on disk.  Exposes :meth:`SmeTrancoFilter.is_large_site` which
returns *True* when a domain (or any of its parents) appears in the top-N
entries of the list.

If the download fails the filter *fails open* — ``is_large_site`` returns
*False* — so the discovery pipeline never crashes due to a network hiccup.
"""

from __future__ import annotations

import csv
import io
import logging
import os
import zipfile
from typing import Optional

import requests

from lead_crawler.services.domain_normalizer import DomainNormalizer

logger = logging.getLogger(__name__)

_TRANCO_ZIP_URL = "https://tranco-list.eu/top-1m.csv.zip"


class SmeTrancoFilter:
    """Download and cache the Tranco top-N list; expose ``is_large_site``."""

    def __init__(
        self,
        top_n: int = 50_000,
        cache_path: Optional[str] = None,
    ) -> None:
        """
        Args:
            top_n: Only load the first *top_n* rows from the Tranco list.
                Domains ranked above this threshold are *not* blocked.
            cache_path: Optional filesystem path for the extracted CSV.
                When provided the filter saves the CSV after downloading and
                reads from disk on subsequent restarts (avoiding a re-download).
        """
        self.top_n = top_n
        self.cache_path = cache_path
        self._large_sites: set[str] | None = None
        self._normalizer = DomainNormalizer()

    # ------------------------------------------------------------------
    # Loading helpers
    # ------------------------------------------------------------------

    def _parse_csv_rows(self, reader: "csv.reader") -> set[str]:  # type: ignore[type-arg]
        """Read up to *top_n* rows from a CSV reader and return the domain set."""
        domains: set[str] = set()
        for i, row in enumerate(reader):
            if i >= self.top_n:
                break
            if len(row) >= 2:
                domain = row[1].strip().lower()
                if domain:
                    domains.add(domain)
        return domains

    def _load_from_disk(self, path: str) -> set[str]:
        with open(path, newline="", encoding="utf-8") as fh:
            return self._parse_csv_rows(csv.reader(fh))

    def _save_to_disk(self, content: str, path: str) -> None:
        try:
            parent = os.path.dirname(path)
            if parent:
                os.makedirs(parent, exist_ok=True)
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(content)
        except Exception as exc:  # pragma: no cover
            logger.warning("Could not write Tranco cache to %s: %s", path, exc)

    def _download_and_parse(self) -> set[str]:
        """Fetch the ZIP from Tranco, extract the CSV, parse, and return domains."""
        response = requests.get(_TRANCO_ZIP_URL, timeout=60)
        response.raise_for_status()

        with zipfile.ZipFile(io.BytesIO(response.content)) as zf:
            csv_name = next(
                (n for n in zf.namelist() if n.lower().endswith(".csv")),
                None,
            )
            if csv_name is None:
                raise ValueError("No CSV file found inside Tranco ZIP")
            csv_bytes = zf.read(csv_name)

        csv_text = csv_bytes.decode("utf-8")

        # Persist to disk cache if requested
        if self.cache_path:
            self._save_to_disk(csv_text, self.cache_path)

        reader = csv.reader(io.StringIO(csv_text))
        return self._parse_csv_rows(reader)

    def _load(self) -> set[str]:
        """Return the in-memory domain set, loading it on first call."""
        if self._large_sites is not None:
            return self._large_sites

        # 1. Try disk cache first
        if self.cache_path and os.path.exists(self.cache_path):
            try:
                self._large_sites = self._load_from_disk(self.cache_path)
                logger.debug(
                    "Loaded %d Tranco domains from disk cache %s",
                    len(self._large_sites),
                    self.cache_path,
                )
                return self._large_sites
            except Exception as exc:
                logger.warning(
                    "Failed to read Tranco cache from %s (%s) — will re-download",
                    self.cache_path,
                    exc,
                )

        # 2. Download from Tranco
        try:
            self._large_sites = self._download_and_parse()
            logger.info("Downloaded Tranco list: %d domains loaded", len(self._large_sites))
        except Exception as exc:
            logger.warning(
                "Tranco download failed (%s) — failing open (is_large_site → False)",
                exc,
            )
            self._large_sites = set()

        return self._large_sites

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def is_large_site(self, domain: str) -> bool:
        """Return *True* when *domain* or any of its parent domains appear in
        the Tranco top-N list.

        Examples::

            filter.is_large_site("zalando.de")       # True  (direct hit)
            filter.is_large_site("www.zalando.de")    # True  (parent hit)
            filter.is_large_site("tiny-shop.de")      # False

        If loading the Tranco list fails the method returns *False* so the
        discovery pipeline continues uninterrupted.
        """
        normalized = self._normalizer.normalize(domain)
        if not normalized:
            return False

        large_sites = self._load()

        # Direct membership check
        if normalized in large_sites:
            return True

        # Check every parent domain  (e.g. "a.b.zalando.de" → check "b.zalando.de",
        # "zalando.de")
        parts = normalized.split(".")
        for i in range(1, len(parts)):
            parent = ".".join(parts[i:])
            if parent in large_sites:
                return True

        return False
