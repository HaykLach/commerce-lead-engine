# Common Crawl Discovery Source

## What source this uses

This discovery flow is built around Common Crawl index-style, columnar metadata (Parquet/table-like records) rather than full raw WARC processing.

The worker supports `domain_discovery_common_crawl` with two backends:

- `duckdb` (local prototype, sample/local parquet friendly)
- `athena` (backend-ready query builder and execution interface)

## Why columnar index over raw WARC for discovery

For domain discovery, we primarily need candidate URLs and hostnames with lightweight filters.
Using index/metadata tables lets us:

- run fast SQL-style filtering (`url LIKE ...`, optional country/niche hints)
- avoid downloading/parsing massive raw WARC payloads first
- iterate quickly on patterns and fit heuristics
- keep ingestion testable with small fixtures

Raw WARC can still be added later for deeper content extraction after candidate selection.

## Local DuckDB prototype

`CommonCrawlDuckDbBackend` supports:

- local `read_parquet('<path>')` queries when `duckdb_dataset_path` is provided
- a sample table mode (for local prototyping/tests)

Expected columns in a parquet dataset are:

- `url` (required)
- `country` (optional)
- `crawl` (optional)
- `subset` (optional)

### Example payload

```json
{
  "job_type": "domain_discovery_common_crawl",
  "patterns": ["/products/", "/product/", "/collections/", "/category/", "/cart", "/checkout"],
  "limit": 500,
  "countries": ["de", "fr", "nl"],
  "niches": ["fashion", "tech", "b2b"],
  "backend": "duckdb",
  "duckdb_dataset_path": "/data/common_crawl_sample.parquet"
}
```

## Athena setup path (later)

`CommonCrawlAthenaBackend` currently provides:

- isolated SQL generation (`build_query`)
- config structure with `us-east-1` default
- clear execution interface (`fetch_candidates`) that is intentionally not yet wired

When wiring execution later:

1. provide an Athena table over Common Crawl index metadata in `us-east-1`
2. configure:
   - `athena_database`
   - `athena_table`
   - `athena_output_location`
   - optional `athena_workgroup`
3. execute `build_query(...)` via boto3 Athena APIs and map rows back to candidates

## Ingestion behavior

Each filtered candidate domain is ingested through internal API endpoint:

- upsert in `domains` using `normalized_domain`
- write `domain_sources` with `source_type=common_crawl`, reference URL, and context metadata
- enqueue follow-up jobs (`homepage_fetch`, `page_classification`) with `trigger_type=discovery`
- skip duplicate queued/running follow-up jobs when same domain/job_type already exists
