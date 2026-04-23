# Common Crawl Discovery Runbook

This runbook explains how to launch a `domain_discovery_common_crawl` crawl job end-to-end in local Docker.

## 1) Start services

From repo root:

```bash
cp .env.example .env
make up
make ps
```

This starts Nginx/Laravel, MySQL, Redis, and the crawler worker container.

## 2) Prepare backend app

Run Laravel setup in the PHP container:

```bash
docker compose exec php-fpm bash -lc 'composer install'
docker compose exec php-fpm bash -lc 'php artisan key:generate'
docker compose exec php-fpm bash -lc 'php artisan migrate --force'
```

## 3) Ensure at least one seed domain exists

The internal `POST /api/v1/internal/crawl-jobs` endpoint requires `domain_id` to exist.

Create a placeholder domain:

```bash
curl -sS -X POST 'http://localhost:8080/api/v1/internal/domains/upsert' \
  -H 'Content-Type: application/json' \
  -d '{
    "domain": "seed.local",
    "normalized_domain": "seed.local",
    "status": "queued"
  }'
```

Copy the returned `data.id` value for the next step.

## 4) Queue a Common Crawl discovery job

### Option A — `cc_index_api` (recommended)

No local parquet required; fetches from Common Crawl index API.

```bash
cat >/tmp/cc-job.json <<'JSON'
{
  "domain_id": 1,
  "status": "queued",
  "trigger_type": "manual",
  "priority": 1,
  "crawl_payload": {
    "job_type": "domain_discovery_common_crawl",
    "backend": "cc_index_api",
    "patterns": ["/products/", "/collections/", "/checkout"],
    "countries": ["de", "nl"],
    "niches": ["fashion"],
    "limit": 100,
    "cc_requests_per_crawl": 2,
    "cc_page_size": 50,
    "cc_delay": 0.5,
    "use_tranco_filter": true,
    "tranco_top_n": 50000,
    "min_sme_score": 0.2
  }
}
JSON

curl -sS -X POST 'http://localhost:8080/api/v1/internal/crawl-jobs' \
  -H 'Content-Type: application/json' \
  --data-binary @/tmp/cc-job.json
```

### Option B — `duckdb` backend

Use this for local prototype against local parquet file.

```bash
curl -sS -X POST 'http://localhost:8080/api/v1/internal/crawl-jobs' \
  -H 'Content-Type: application/json' \
  -d '{
    "domain_id": 1,
    "status": "queued",
    "trigger_type": "manual",
    "priority": 1,
    "crawl_payload": {
      "job_type": "domain_discovery_common_crawl",
      "backend": "duckdb",
      "duckdb_dataset_path": "/workspace/apps/crawler/tests/fixtures/common_crawl_sample.parquet",
      "patterns": ["/products/", "/category/", "/checkout"],
      "countries": ["de"],
      "niches": ["fashion"],
      "limit": 100
    }
  }'
```

## 5) Run the worker loop

In a second terminal:

```bash
docker compose exec crawler bash -lc 'WORKER_LOG_LEVEL=INFO python run_worker.py'
```

The worker continuously polls `GET /api/v1/internal/crawl-jobs/next` and processes queued jobs.

## 6) Verify discovery ingestion

Each discovered domain is ingested through `/api/v1/internal/discovered-domains/ingest`, and by default the backend enqueues follow-up jobs (`homepage_fetch`, `page_classification`) per domain.

Quick checks:

```bash
# Next queued job (204 means none)
curl -i 'http://localhost:8080/api/v1/internal/crawl-jobs/next'

# Query leads once pipeline has downstream results
curl -sS 'http://localhost:8080/api/v1/internal/leads?per_page=5'
```

## Notes

- `backend: athena` is present as a query-building interface but execution wiring is intentionally not finished yet.
- `cc_index_api` defaults to project-defined crawl IDs if `cc_crawls` is omitted.
- `cc_page_size` is capped to 100.

## Troubleshooting: job finished but discovered 0 domains

If your worker log shows `crawl_payload` only contains `{"job_type":"domain_discovery_common_crawl"}`
and summary says `backend: "duckdb"` with `discovered_count: 0`, that means the backend fell back to defaults
(duckdb + in-memory empty sample table), so no candidates were available.

Check the stored payload first:

```bash
curl -sS 'http://localhost:8080/api/v1/internal/crawl-jobs/next'
```

Or inspect in MySQL:

```bash
docker compose exec mysql mysql -uapp -papp commerce_leads \
  -e "SELECT id, status, JSON_PRETTY(crawl_payload) AS crawl_payload FROM crawl_jobs ORDER BY id DESC LIMIT 5;"
```

If fields like `backend`, `countries`, `patterns`, etc. are missing, recreate the job using the JSON-file method above
to avoid shell-escaping issues.

### Important: `curl` must include the request body

If you run only:

```bash
curl -sS -X POST 'http://localhost:8080/api/v1/internal/crawl-jobs' \
  -H 'Content-Type: application/json'
```

then no payload is sent, so you will either get validation errors or create an incomplete request in other wrappers.
Always include `--data-binary @/tmp/cc-job.json` (or `-d '{...}'`).

### See detailed Common Crawl logs

With the updated worker, Common Crawl jobs now print:

- resolved backend/pattern/country config
- number of discovered candidates before ingest
- sample discovered domains (first 10)
- per-request CC Index URL + returned record counts (from backend logger)

Run:

```bash
docker compose exec crawler bash -lc 'WORKER_LOG_LEVEL=INFO python run_worker.py'
```
