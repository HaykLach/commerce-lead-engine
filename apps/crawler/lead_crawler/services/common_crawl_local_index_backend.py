"""MySQL-backed local Common Crawl index backend."""

from __future__ import annotations

import json
import logging
import os
from dataclasses import dataclass

from lead_crawler.services.common_crawl_discovery_service import CommonCrawlCandidateRow

logger = logging.getLogger(__name__)


@dataclass(slots=True)
class CommonCrawlLocalIndexConfig:
    mysql_host: str = os.getenv("MYSQL_HOST", "mysql")
    mysql_port: int = int(os.getenv("MYSQL_PORT", "3306"))
    mysql_database: str = os.getenv("MYSQL_DATABASE", "commerce_leads")
    mysql_user: str = os.getenv("MYSQL_USER", "app")
    mysql_password: str = os.getenv("MYSQL_PASSWORD", "app")
    min_sme_score: float = 0.0


class CommonCrawlLocalIndexBackend:
    """Reads candidate domains from local MySQL common_crawl_domains table."""

    def __init__(self, config: CommonCrawlLocalIndexConfig | None = None) -> None:
        self.config = config or CommonCrawlLocalIndexConfig()

    def _mysql_connection(self):
        try:
            import MySQLdb  # type: ignore
        except ModuleNotFoundError as exc:  # pragma: no cover
            raise RuntimeError("mysqlclient is required for local Common Crawl index backend.") from exc

        return MySQLdb.connect(
            host=self.config.mysql_host,
            port=self.config.mysql_port,
            user=self.config.mysql_user,
            passwd=self.config.mysql_password,
            db=self.config.mysql_database,
            charset="utf8mb4",
            use_unicode=True,
        )

    def fetch_candidates(
        self,
        patterns: list[str],  # noqa: ARG002 - interface compatibility
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,  # noqa: ARG002 - interface compatibility
    ) -> list[CommonCrawlCandidateRow]:
        tld_filters = [country.lower() for country in (countries or []) if str(country).strip()]
        effective_limit = max(1, int(limit))

        where_clauses = ["ecommerce_score >= %s"]
        params: list = [float(self.config.min_sme_score)]

        if tld_filters:
            placeholders = ", ".join(["%s"] * len(tld_filters))
            where_clauses.append(f"tld IN ({placeholders})")
            params.extend(tld_filters)

        sql = f"""
SELECT domain, tld, ecommerce_score, matched_patterns, source_url, crawl_id, last_seen_at
FROM common_crawl_domains
WHERE {" AND ".join(where_clauses)}
ORDER BY ecommerce_score DESC, last_seen_at DESC
LIMIT %s
""".strip()
        params.append(effective_limit)

        logger.info(
            "Local Common Crawl index query: countries=%s min_sme_score=%s limit=%s",
            tld_filters,
            self.config.min_sme_score,
            effective_limit,
        )

        with self._mysql_connection() as conn:
            with conn.cursor() as cursor:
                cursor.execute(sql, params)
                rows = cursor.fetchall()

        logger.info("Local Common Crawl index returned %d row(s)", len(rows))

        candidates: list[CommonCrawlCandidateRow] = []
        for domain, tld, ecommerce_score, matched_patterns, source_url, crawl_id, _last_seen_at in rows:
            parsed_patterns = []
            if matched_patterns:
                try:
                    parsed_patterns = json.loads(matched_patterns)
                except (TypeError, json.JSONDecodeError):
                    parsed_patterns = []

            first_pattern = ""
            if isinstance(parsed_patterns, list) and parsed_patterns:
                first_pattern = str(parsed_patterns[0])

            candidate_url = source_url or f"https://{domain}"
            source_metadata = {
                "backend": "local_common_crawl_index",
                "tld": tld,
                "ecommerce_score": float(ecommerce_score or 0.0),
                "matched_patterns": parsed_patterns if isinstance(parsed_patterns, list) else [],
                "crawl": crawl_id,
            }
            candidates.append(
                CommonCrawlCandidateRow(
                    candidate_url=candidate_url,
                    normalized_domain=str(domain),
                    matched_pattern=first_pattern,
                    source_metadata=source_metadata,
                )
            )

        return candidates

    def fetch_domains_for_ingest(
        self,
        country: str,
        min_sme_score: float,
        limit: int,
        exclude_existing_domains: bool = True,
    ) -> list[dict]:
        effective_country = str(country or "").strip().lower()
        effective_limit = max(1, int(limit))
        effective_min_sme_score = float(min_sme_score or 0.0)

        where_clauses = [
            "c.country = %s",
            "c.ecommerce_score >= %s",
        ]
        params: list = [effective_country, effective_min_sme_score]

        if exclude_existing_domains:
            where_clauses.append(
                "NOT EXISTS (SELECT 1 FROM domains d WHERE d.domain = c.domain)"
            )

        sql = f"""
SELECT
    c.id,
    c.domain,
    c.country,
    c.tld,
    c.ecommerce_score,
    c.source_url,
    c.matched_patterns,
    c.country_signals,
    c.crawl_id
FROM common_crawl_domains c
WHERE {" AND ".join(where_clauses)}
ORDER BY c.ecommerce_score DESC, c.last_seen_at DESC, c.id DESC
LIMIT %s
""".strip()
        params.append(effective_limit)

        with self._mysql_connection() as conn:
            with conn.cursor() as cursor:
                cursor.execute(sql, params)
                rows = cursor.fetchall()

        columns = [
            "id",
            "domain",
            "country",
            "tld",
            "ecommerce_score",
            "source_url",
            "matched_patterns",
            "country_signals",
            "crawl_id",
        ]
        return [self._coerce_row_dict(row, columns) for row in rows]

    def local_index_debug_counts(
        self,
        country: str,
        min_sme_score: float,
        exclude_existing_domains: bool = True,
    ) -> dict:
        effective_country = str(country or "").strip().lower()
        effective_min_sme_score = float(min_sme_score or 0.0)

        with self._mysql_connection() as conn:
            with conn.cursor() as cursor:
                cursor.execute(
                    "SELECT COUNT(*) FROM common_crawl_domains WHERE country = %s",
                    [effective_country],
                )
                total_for_country = int(cursor.fetchone()[0])

                cursor.execute(
                    """
SELECT COUNT(*)
FROM common_crawl_domains
WHERE country = %s AND ecommerce_score >= %s
""".strip(),
                    [effective_country, effective_min_sme_score],
                )
                total_for_country_with_score = int(cursor.fetchone()[0])

                total_after_exclude_existing = None
                if exclude_existing_domains:
                    cursor.execute(
                        """
SELECT COUNT(*)
FROM common_crawl_domains c
WHERE c.country = %s
  AND c.ecommerce_score >= %s
  AND NOT EXISTS (
      SELECT 1 FROM domains d WHERE d.domain = c.domain
  )
""".strip(),
                        [effective_country, effective_min_sme_score],
                    )
                    total_after_exclude_existing = int(cursor.fetchone()[0])

        return {
            "country_total": total_for_country,
            "country_with_min_score_total": total_for_country_with_score,
            "country_with_min_score_excluding_existing_total": total_after_exclude_existing,
        }

    @staticmethod
    def _coerce_row_dict(row, columns: list[str]) -> dict:
        if isinstance(row, dict):
            return {column: row.get(column) for column in columns}

        return {column: row[index] if index < len(row) else None for index, column in enumerate(columns)}
