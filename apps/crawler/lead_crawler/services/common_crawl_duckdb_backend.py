"""DuckDB backend for Common Crawl index-style datasets."""

from __future__ import annotations

from urllib.parse import urlparse

from lead_crawler.services.common_crawl_discovery_service import CommonCrawlCandidateRow
from lead_crawler.services.common_crawl_url_pattern_builder import CommonCrawlUrlPatternBuilder
from lead_crawler.services.domain_normalizer import DomainNormalizer


class CommonCrawlDuckDbBackend:
    def __init__(self, database: str = ":memory:", dataset_path: str | None = None) -> None:
        self.database = database
        self.dataset_path = dataset_path
        self.normalizer = DomainNormalizer()

    def fetch_candidates(
        self,
        patterns: list[str],
        limit: int,
        countries: list[str] | None = None,
        niches: list[str] | None = None,
    ) -> list[CommonCrawlCandidateRow]:
        try:
            import duckdb  # type: ignore
        except ModuleNotFoundError as exc:
            raise RuntimeError("duckdb is required for backend=duckdb") from exc

        where_parts = []
        pattern_clause = CommonCrawlUrlPatternBuilder.like_clauses("url", patterns)
        if pattern_clause:
            where_parts.append(f"({pattern_clause})")

        normalized_countries = [country.lower() for country in (countries or []) if str(country).strip()]
        if normalized_countries:
            joined = ",".join([f"'{country}'" for country in normalized_countries])
            where_parts.append(f"lower(coalesce(country, '')) IN ({joined})")

        normalized_niches = [niche.lower() for niche in (niches or []) if str(niche).strip()]
        if normalized_niches:
            niche_checks = [f"lower(url) LIKE '%{CommonCrawlUrlPatternBuilder.escape_sql_like(niche)}%'" for niche in normalized_niches]
            where_parts.append(f"({' OR '.join(niche_checks)})")

        where_sql = " AND ".join(where_parts) if where_parts else "1 = 1"

        source = self.dataset_path or "common_crawl_index_sample"
        if self.dataset_path:
            from_expr = f"read_parquet('{self.dataset_path}')"
        else:
            from_expr = "common_crawl_index_sample"

        sql = f"""
            SELECT
                url,
                coalesce(country, '') AS country,
                coalesce(crawl, '') AS crawl,
                coalesce(subset, '') AS subset
            FROM {from_expr}
            WHERE {where_sql}
            LIMIT {max(1, int(limit) * 5)}
        """

        with duckdb.connect(self.database) as conn:
            if not self.dataset_path:
                conn.execute(
                    """
                    CREATE TABLE IF NOT EXISTS common_crawl_index_sample (
                        url VARCHAR,
                        country VARCHAR,
                        crawl VARCHAR,
                        subset VARCHAR
                    )
                    """
                )
            rows = conn.execute(sql).fetchall()

        results: list[CommonCrawlCandidateRow] = []
        normalized_patterns = CommonCrawlUrlPatternBuilder.normalize_patterns(patterns)
        for url, country, crawl, subset in rows:
            normalized_domain = self.normalizer.normalize(urlparse(url).netloc)
            if not normalized_domain:
                continue

            matched_pattern = next((pattern for pattern in normalized_patterns if pattern in url.lower()), "")
            results.append(
                CommonCrawlCandidateRow(
                    candidate_url=url,
                    normalized_domain=normalized_domain,
                    matched_pattern=matched_pattern,
                    source_metadata={
                        "backend": "duckdb",
                        "country": country or None,
                        "crawl": crawl or None,
                        "subset": subset or None,
                        "source": source,
                    },
                )
            )
            if len(results) >= max(1, int(limit)):
                break

        return results
