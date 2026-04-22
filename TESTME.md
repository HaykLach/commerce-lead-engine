# TESTME — End-to-end testing guide

This document walks through every layer of the stack, from isolated Python unit
tests all the way to a live Laravel → crawler → database round-trip.

---

## 0. Prerequisites

| Tool | Version | Check |
|---|---|---|
| Docker + Compose | ≥ 24 | `docker compose version` |
| Python | 3.10+ | `python3 --version` |
| make | any | `make --version` |
| curl / httpie | any | `curl --version` |

All commands below are run from the **project root** unless stated otherwise.

---

## 1. Python unit tests (no Docker needed)

These run entirely in-process with mocked HTTP — no internet, no Laravel.

```bash
cd apps/crawler

# Install test dependencies (once)
python3 -m pip install pytest requests beautifulsoup4

# Run only the new SME-filter and CC-index-API tests
python3 -m pytest tests/test_common_crawl_index_api_backend.py \
                  tests/test_sme_domain_filter.py \
                  -v

# Run the full suite (111 tests expected)
python3 -m pytest tests/ -v --ignore=tests/test_discovery_services.py
```

**Expected:** all selected tests green. `test_discovery_services.py` has 4
pre-existing failures unrelated to this feature (bs4 mock issue) — ignore them.

---

## 2. Smoke-test filter logic in the Python REPL

```bash
cd apps/crawler
python3
```

```python
from lead_crawler.services.common_crawl_domain_filter import CommonCrawlDomainFilter

f = CommonCrawlDomainFilter(min_sme_score=0.2)

cases = [
    # (domain, should_pass)
    ("zalando.de",         False),   # DACH giant — denylist
    ("otto.de",            False),   # DACH giant — denylist
    ("coolblue.nl",        False),   # NL giant — denylist
    ("myshopify.com",      False),   # platform storefront
    ("shop123456.de",      False),   # 3+ consecutive digits in SLD
    ("bigcorp.de",         False),   # "corp" substring token
    ("musterholding.de",   False),   # "holding" substring token
    ("tiny-shop.de",       True),
    ("boutique-berlin.de", True),
    ("modehaus.nl",        True),
]

print(f"{'Domain':35s}  {'Expected':8s}  {'Got':8s}  {'Score':6s}  OK?")
print("-" * 75)
for domain, expected in cases:
    got   = f.should_include(domain)
    score = f.sme_score(domain)
    ok    = "✅" if got == expected else "❌ FAIL"
    print(f"{domain:35s}  {str(expected):8s}  {str(got):8s}  {score:.2f}    {ok}")
```

All rows should show ✅.

---

## 3. Smoke-test the CC Index API backend (real HTTP, ~10 s)

Hits the live Common Crawl CDX API. Requires internet.

```bash
cd apps/crawler
python3 - <<'EOF'
from lead_crawler.services.common_crawl_index_api_backend import (
    CommonCrawlIndexApiBackend, CommonCrawlIndexApiConfig
)
from lead_crawler.services.common_crawl_domain_filter import CommonCrawlDomainFilter
from lead_crawler.services.common_crawl_discovery_service import CommonCrawlDiscoveryService

cfg = CommonCrawlIndexApiConfig(
    crawls=["CC-MAIN-2025-13"],   # one crawl
    tld_targets=["de"],           # German TLD only
    requests_per_crawl=1,         # one CDX page per pattern
    page_size=10,
    request_delay_seconds=0,      # no throttle for manual test
)

service = CommonCrawlDiscoveryService(
    backend=CommonCrawlIndexApiBackend(config=cfg),
    domain_filter=CommonCrawlDomainFilter(min_sme_score=0.1),
)

results = service.discover(
    patterns=["/products/", "/shop/"],
    limit=20,
    countries=["de"],
)

print(f"\nDiscovered {len(results)} candidates:\n")
for r in results:
    score = CommonCrawlDomainFilter().sme_score(r.domain)
    print(f"  {r.domain:40s}  score={score:.2f}")
EOF
```

**Expected:** list of real German e-commerce domains, no enterprise giants.

---

## 4. Test with US and UAE targets

```bash
cd apps/crawler
python3 - <<'EOF'
from lead_crawler.services.common_crawl_index_api_backend import (
    CommonCrawlIndexApiBackend, CommonCrawlIndexApiConfig, COUNTRY_TO_TLDS
)

# Verify TLD derivation for US and AE
from lead_crawler.services.common_crawl_index_api_backend import CommonCrawlIndexApiBackend, CommonCrawlIndexApiConfig

cfg = CommonCrawlIndexApiConfig()
b = CommonCrawlIndexApiBackend(config=cfg)

print("US  →", b._get_tlds(["us"]))   # expect ['com', 'us']
print("AE  →", b._get_tlds(["ae"]))   # expect ['ae']
print("DE  →", b._get_tlds(["de"]))   # expect ['de']
print("DACH→", b._get_tlds(["de","at","ch"]))  # expect ['de', 'at', 'ch']

# Verify TLD→country inference (for homepage fetch enrichment)
import run_worker
for tld, expected in [("ae", "AE"), ("us", "US"), ("at", "AT"), ("ch", "CH"), ("de", "DE")]:
    got = run_worker.TLD_COUNTRY_HINTS.get(tld)
    ok  = "✅" if got == expected else f"❌ got {got}"
    print(f"  TLD .{tld} → country {got}  {ok}")
EOF
```

**Expected:** all ✅ and correct TLD lists.

---

## 5. Start the Docker stack

```bash
# From project root
make up          # builds images + starts all containers
make ps          # verify all containers are Up
```

Expected containers: `cle_nginx`, `cle_php`, `cle_mysql`, `cle_redis`, `cle_crawler`.

---

## 6. Bootstrap Laravel (first time only)

```bash
make backend-shell
# inside the PHP container:
cp .env.example .env
php artisan key:generate

# Point to the MySQL container (already in docker-compose env)
# Edit .env:
#   DB_CONNECTION=mysql
#   DB_HOST=mysql
#   DB_PORT=3306
#   DB_DATABASE=commerce_leads
#   DB_USERNAME=app
#   DB_PASSWORD=app

php artisan migrate --force
php artisan db:seed --force   # if seeders exist
exit
```

Verify the API is up:

```bash
curl -s http://localhost:8080/api/v1/internal/crawl-jobs/next
# Expected: 204 No Content  (queue is empty — that's correct)
```

---

## 7. Submit a domain discovery job via the Laravel API

```bash
curl -s -X POST http://localhost:8080/api/v1/internal/crawl-jobs \
  -H "Content-Type: application/json" \
  -d '{
    "trigger_type": "manual",
    "crawl_payload": {
      "job_type": "domain_discovery_common_crawl",
      "backend": "cc_index_api",
      "cc_crawls": ["CC-MAIN-2025-13"],
      "cc_tld_targets": ["de", "at", "ch", "nl", "se", "ae", "com"],
      "cc_requests_per_crawl": 1,
      "cc_page_size": 20,
      "cc_delay": 0.5,
      "patterns": ["/products/", "/shop/", "/cart", "/checkout"],
      "limit": 30,
      "countries": ["de", "at", "ch", "nl", "se", "ae", "us"],
      "use_tranco_filter": true,
      "tranco_top_n": 50000,
      "tranco_cache_path": "/tmp/tranco_cache.csv",
      "min_sme_score": 0.2,
      "enqueue_homepage_fetch": false,
      "enqueue_page_classification": false
    }
  }' | python3 -m json.tool
```

Note the `id` from the response — e.g. `1`.

Verify it is queued:

```bash
curl -s http://localhost:8080/api/v1/internal/crawl-jobs/next | python3 -m json.tool
# Should return the job with status "queued"
```

---

## 8. Run the Python worker (processes the job)

```bash
make crawler-shell
# inside the crawler container:
cd /workspace/apps/crawler

# Install dependencies if needed
pip install requests beautifulsoup4 --quiet

# Run the worker — it will pick up the queued job, run it, and exit the loop
# after the job completes (Ctrl+C after it prints "Completed job N")
python3 run_worker.py
```

Watch the output for:
```
[Worker] Processing job 1
[Worker] POST .../crawl-jobs/1/start
[Worker] POST .../discovered-domains/ingest    ← repeated for each domain
[Worker] Completed job 1
```

---

## 9. Verify results in the database

```bash
make mysql-shell
```

```sql
-- How many domains were discovered?
SELECT COUNT(*) FROM domains;

-- What do they look like?
SELECT normalized_domain, country, status, first_seen_at
FROM domains
ORDER BY first_seen_at DESC
LIMIT 20;

-- Which sources brought them in?
SELECT source_type, source_name, COUNT(*) as cnt
FROM domain_sources
GROUP BY source_type, source_name;

-- Check source context for CC metadata
SELECT d.normalized_domain, ds.context
FROM domain_sources ds
JOIN domains d ON d.id = ds.domain_id
WHERE ds.source_type = 'common_crawl'
LIMIT 5;
```

**Expected:**
- `domains` contains real shops (no Zalando, Otto, Coolblue etc.)
- `source_type = 'common_crawl'` in `domain_sources`
- `context` JSON contains `backend: "cc_index_api"`, `crawl`, `tld_target`
- `country` is `NULL` at this stage (populated by homepage_fetch later — see step 10)

---

## 10. Verify country enrichment via homepage fetch

Re-submit the same job but allow follow-up jobs to be created, **or** directly
call the homepage_fetch job for one discovered domain:

```bash
# From outside Docker — get the domain ID of any discovered domain
DOMAIN=$(curl -s http://localhost:8080/api/v1/internal/crawl-jobs/next \
  | python3 -c "import sys,json; j=json.load(sys.stdin); print(j['data']['crawl_payload']['domain'])" 2>/dev/null)

# Or just pick a .de domain you saw in step 9, e.g.:
DOMAIN="tiny-shop.de"

curl -s -X POST http://localhost:8080/api/v1/internal/crawl-jobs \
  -H "Content-Type: application/json" \
  -d "{
    \"trigger_type\": \"manual\",
    \"crawl_payload\": {
      \"job_type\": \"homepage_fetch\",
      \"domain\": \"$DOMAIN\",
      \"enqueue_page_classification\": false
    }
  }"
```

Run the worker again (`python3 run_worker.py` in the crawler container), then
re-check the DB:

```sql
SELECT normalized_domain, country, platform, confidence
FROM domains
WHERE normalized_domain = 'tiny-shop.de';
```

**Expected:** `country` now populated (e.g. `DE` for a .de domain).

---

## 11. Check the SME score is correctly blocking giants at full-stack level

Submit a job where known giants would appear if filtering were off, and confirm
they are absent:

```bash
# Query one pattern that Zalando definitely has
curl -s -X POST http://localhost:8080/api/v1/internal/crawl-jobs \
  -H "Content-Type: application/json" \
  -d '{
    "trigger_type": "manual",
    "crawl_payload": {
      "job_type": "domain_discovery_common_crawl",
      "backend": "cc_index_api",
      "cc_crawls": ["CC-MAIN-2025-13"],
      "cc_tld_targets": ["de"],
      "cc_requests_per_crawl": 1,
      "cc_page_size": 50,
      "cc_delay": 0,
      "patterns": ["/products/"],
      "limit": 100,
      "countries": ["de"],
      "min_sme_score": 0.0,
      "enqueue_homepage_fetch": false,
      "enqueue_page_classification": false
    }
  }'
```

After the worker runs, check in MySQL:

```sql
-- None of these should appear
SELECT normalized_domain FROM domains
WHERE normalized_domain IN (
  'zalando.de','otto.de','mediamarkt.de','saturn.de',
  'lidl.de','aldi.de','rewe.de','dm.de','douglas.de'
);
-- Expected: 0 rows
```

---

## 12. Quick reference — what each layer owns

```
Job payload (JSON)
    │
    ▼
Laravel /crawl-jobs  ──────────────────── stores CrawlJob row, queued
    │
    ▼  (worker polls /crawl-jobs/next)
run_worker.py
    ├─ _build_common_crawl_backend()      reads cc_* keys, builds Config
    ├─ SmeTrancoFilter()                  downloads Tranco if use_tranco_filter=true
    ├─ CommonCrawlDomainFilter()          hard-block + SME score
    └─ CommonCrawlDiscoveryService.discover()
            │
            ▼
        CommonCrawlIndexApiBackend.fetch_candidates()
            └─ GET index.commoncrawl.org/{crawl}-index
                   ?url=*.{tld}/{pattern}/*
                   &output=json&fl=url,status,mime,languages
            │
            ▼  (filters status, MIME, deduplicates)
        CommonCrawlCandidateRow list
            │
            ▼  (should_include → sme_score → tranco check)
        filtered domain list
    │
    ▼  (for each domain)
Laravel POST /discovered-domains/ingest
    ├─ creates/updates domains row (no country yet)
    ├─ creates domain_sources row  (source_type=common_crawl, context=CC metadata)
    └─ optionally enqueues homepage_fetch + page_classification CrawlJobs
            │
            ▼  (next worker loop)
        homepage_fetch job
            └─ fetches HTML, runs WhatWeb + fingerprint
            └─ calls infer_country() → TLD_COUNTRY_HINTS (de/at/ch/nl/se/ae/us …)
            └─ POST /domains/upsert  → populates domains.country
```

---

## 13. Common failure modes

| Symptom | Cause | Fix |
|---|---|---|
| `204 No Content` from `/crawl-jobs/next` | Queue is empty | Submit a job (step 7) |
| `ModuleNotFoundError: bs4` | Missing Python dep | `pip install beautifulsoup4` |
| CDX returns `[]` for all pages | Pattern has no matches in that crawl/TLD | Try a broader pattern or different crawl ID |
| `country` stays NULL after discovery | Expected — country is set by homepage_fetch | Run homepage_fetch job (step 10) |
| `country` stays NULL after homepage_fetch for .ae or .us | Was a bug — `TLD_COUNTRY_HINTS` was missing these | Fixed in this branch — `at/ch/ae/us` now included |
| Known giants appear in DB | `min_sme_score` set too low or denylist bypass | Confirm `common_crawl_domain_filter.py` DEFAULT_DENYLIST is loaded |
| `requests_per_crawl` pages not enough | CDX index sparse for that TLD/pattern | Increase `cc_requests_per_crawl` or try different patterns |
