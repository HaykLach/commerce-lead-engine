"""Athena backend starter for Common Crawl index datasets."""

from __future__ import annotations

from dataclasses import dataclass

from lead_crawler.services.common_crawl_discovery_service import CommonCrawlCandidateRow
from lead_crawler.services.common_crawl_url_pattern_builder import CommonCrawlUrlPatternBuilder


@dataclass(slots=True)
class CommonCrawlAthenaConfig:
    database: str
    table: str
    output_location: str
    region_name: str = "us-east-1"
    workgroup: str | None = None


class CommonCrawlAthenaBackend:
    def __init__(self, config: CommonCrawlAthenaConfig) -> None:
        self.config = config

    def build_query(
        self,
        patterns: list[str],
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,
    ) -> str:
        where_parts = []
        pattern_clause = CommonCrawlUrlPatternBuilder.like_clauses("url", patterns)
        if pattern_clause:
            where_parts.append(f"({pattern_clause})")

        country_tokens = [str(country).strip().lower() for country in (countries or []) if str(country).strip()]
        if country_tokens:
            joined = ", ".join([f"'{token}'" for token in country_tokens])
            where_parts.append(f"lower(coalesce(country, '')) IN ({joined})")

        niche_tokens = [str(niche).strip().lower() for niche in (niches or []) if str(niche).strip()]
        if niche_tokens:
            checks = [f"lower(url) LIKE '%{CommonCrawlUrlPatternBuilder.escape_sql_like(token)}%'" for token in niche_tokens]
            where_parts.append(f"({' OR '.join(checks)})")

        where_sql = " AND ".join(where_parts) if where_parts else "1 = 1"

        return (
            "SELECT url, coalesce(country, '') AS country, coalesce(crawl, '') AS crawl "
            f"FROM {self.config.database}.{self.config.table} "
            f"WHERE {where_sql} "
            f"LIMIT {max(1, int(limit) * 5)}"
        )

    def fetch_candidates(
        self,
        patterns: list[str],
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,
    ) -> list[CommonCrawlCandidateRow]:
        query = self.build_query(
            patterns=patterns,
            limit=limit,
            countries=countries,
            niches=niches,
        )
        raise NotImplementedError(
            "Athena execution is not wired in this repository yet. "
            f"Use build_query() and run in Athena (region={self.config.region_name}). Query: {query}"
        )
