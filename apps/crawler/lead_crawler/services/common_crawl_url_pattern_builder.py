"""Pattern helpers for Common Crawl query builders."""

from __future__ import annotations


class CommonCrawlUrlPatternBuilder:
    DEFAULT_PATTERNS = [
        "/products/",
        "/product/",
        "/collections/",
        "/category/",
        "/cart",
        "/checkout",
    ]

    @classmethod
    def normalize_patterns(cls, patterns: list[str] | None) -> list[str]:
        raw = patterns or cls.DEFAULT_PATTERNS
        cleaned = [str(item).strip().lower() for item in raw if str(item).strip()]
        return list(dict.fromkeys(cleaned))

    @classmethod
    def like_clauses(cls, column_name: str, patterns: list[str]) -> str:
        normalized = cls.normalize_patterns(patterns)
        return " OR ".join([f"lower({column_name}) LIKE '%{cls.escape_sql_like(pattern)}%'" for pattern in normalized])

    @staticmethod
    def escape_sql_like(pattern: str) -> str:
        return pattern.replace("'", "''")
