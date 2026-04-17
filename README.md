# commerce-lead-engine

Monorepo for an ecommerce lead generation system with:

- Laravel backend API/admin service
- Scrapy crawler for domain discovery and page analysis
- Modular lead-scoring configuration
- MySQL, Redis, and WhatWeb-powered detection support

## Proposed monorepo structure

```text
commerce-lead-engine/
├─ AGENTS.md
├─ README.md
├─ .github/
│  └─ workflows/
│     ├─ ci-backend.yml
│     ├─ ci-crawler.yml
│     └─ lint-and-contracts.yml
├─ apps/
│  ├─ backend/
│  │  ├─ app/
│  │  │  ├─ Domain/
│  │  │  │  ├─ Discovery/
│  │  │  │  ├─ Detection/
│  │  │  │  ├─ Classification/
│  │  │  │  └─ Scoring/
│  │  │  ├─ Http/
│  │  │  │  ├─ Controllers/
│  │  │  │  ├─ Requests/
│  │  │  │  └─ Resources/
│  │  │  ├─ Jobs/
│  │  │  ├─ Services/
│  │  │  └─ Support/
│  │  ├─ bootstrap/
│  │  ├─ config/
│  │  │  ├─ lead_scoring.php
│  │  │  ├─ fingerprints.php
│  │  │  └─ crawler.php
│  │  ├─ database/
│  │  │  ├─ migrations/
│  │  │  ├─ factories/
│  │  │  └─ seeders/
│  │  ├─ routes/
│  │  │  ├─ api.php
│  │  │  ├─ web.php
│  │  │  └─ internal.php
│  │  ├─ storage/
│  │  ├─ tests/
│  │  │  ├─ Feature/
│  │  │  ├─ Unit/
│  │  │  └─ Fixtures/
│  │  ├─ composer.json
│  │  └─ phpunit.xml
│  └─ crawler/
│     ├─ crawler/
│     │  ├─ spiders/
│     │  │  ├─ discovery_spider.py
│     │  │  ├─ platform_spider.py
│     │  │  └─ classification_spider.py
│     │  ├─ classifiers/
│     │  ├─ detectors/
│     │  ├─ pipelines/
│     │  ├─ middlewares/
│     │  ├─ items.py
│     │  ├─ settings.py
│     │  └─ contracts/
│     │     └─ lead_event.schema.json
│     ├─ tests/
│     │  ├─ unit/
│     │  ├─ integration/
│     │  └─ fixtures/
│     ├─ scrapy.cfg
│     ├─ pyproject.toml
│     └─ requirements.txt
├─ docker/
│  ├─ compose.yml
│  ├─ compose.dev.yml
│  ├─ .env.example
│  ├─ backend/
│  │  ├─ Dockerfile
│  │  └─ php.ini
│  ├─ crawler/
│  │  ├─ Dockerfile
│  │  └─ entrypoint.sh
│  ├─ mysql/
│  │  ├─ Dockerfile
│  │  └─ init/
│  │     ├─ 001-schema.sql
│  │     └─ 002-seed-dev.sql
│  ├─ redis/
│  │  └─ redis.conf
│  └─ whatweb/
│     ├─ Dockerfile
│     ├─ plugins/
│     └─ fingerprints/
├─ docs/
│  ├─ architecture/
│  │  ├─ overview.md
│  │  ├─ domain-discovery.md
│  │  ├─ platform-detection.md
│  │  ├─ page-classification.md
│  │  └─ lead-scoring.md
│  ├─ setup/
│  │  ├─ local-development.md
│  │  ├─ environment-variables.md
│  │  └─ docker-services.md
│  ├─ api-contracts/
│  │  ├─ lead-ingest.openapi.yaml
│  │  └─ crawler-events.schema.json
│  ├─ fixtures/
│  │  ├─ html-samples/
│  │  ├─ whatweb-samples/
│  │  └─ scoring-samples/
│  └─ adr/
│     ├─ 0001-monorepo-layout.md
│     └─ 0002-detection-and-scoring-boundaries.md
├─ packages/
│  ├─ fingerprints/
│  │  ├─ rules/
│  │  │  ├─ shopify.yml
│  │  │  ├─ woocommerce.yml
│  │  │  └─ magento.yml
│  │  ├─ tests/
│  │  └─ README.md
│  ├─ scoring/
│  │  ├─ models/
│  │  │  ├─ default-score.yml
│  │  │  └─ enterprise-score.yml
│  │  ├─ weights/
│  │  ├─ tests/
│  │  └─ README.md
│  └─ contracts/
│     ├─ events/
│     │  └─ lead_discovered.v1.json
│     ├─ api/
│     │  └─ lead_response.v1.json
│     └─ README.md
├─ scripts/
│  ├─ dev/
│  │  ├─ up.sh
│  │  ├─ down.sh
│  │  └─ reset.sh
│  ├─ lint/
│  │  ├─ backend.sh
│  │  └─ crawler.sh
│  └─ ci/
│     └─ validate-contracts.sh
└─ .editorconfig
```

## Top-level folder responsibilities

- `.github/` — CI/CD workflows for backend, crawler, and shared contract validation.
- `apps/` — deployable applications only (Laravel backend and Scrapy crawler).
- `docker/` — local development container definitions (compose + per-service Dockerfiles/config).
- `docs/` — architecture, setup/runbooks, fixtures documentation, and API contract references.
- `packages/` — shared, versioned domain assets (fingerprint rules, scoring configs, and contract schemas).
- `scripts/` — local/CI automation wrappers for repeatable developer workflows.

## Naming conventions

- **Version contracts explicitly**: use suffixes like `.v1.json` and keep breaking changes in new versions (e.g., `lead_discovered.v2.json`).
- **Domain-first folder names**: `Discovery`, `Detection`, `Classification`, `Scoring` across backend and docs.
- **Rule files by platform**: `packages/fingerprints/rules/<platform>.yml` (for example `shopify.yml`).
- **Scoring profiles by intent**: `packages/scoring/models/<profile>-score.yml` (for example `enterprise-score.yml`).
- **SQL migration ordering**: prefix with numeric sequence `001-...sql`, `002-...sql` under Docker init scripts.
- **Fixtures separated by source**: use `html-samples/`, `whatweb-samples/`, and `scoring-samples/` to avoid mixed fixture types.
- **Environment files**: keep templates as `.env.example` and never commit real secrets.

## Key design principles

- No paid APIs.
- Custom fingerprint detection for platform identification.
- Configurable scoring logic.
- Modular architecture between crawling, detection, and scoring.

## Local development setup

This repository includes a Docker-based local environment for the monorepo assumptions below:

- Laravel backend path: `apps/backend`
- Scrapy crawler path: `apps/crawler`

### Services included

- `nginx` (entrypoint HTTP server for backend)
- `php-fpm` (Laravel runtime)
- `mysql` (persistent local database)
- `redis` (cache/queue)
- `crawler` (Python container with Scrapy dependencies + WhatWeb)

### 1) Prepare environment variables

```bash
cp .env.example .env
```

Adjust ports and credentials if needed.

### 2) Start the stack

```bash
make up
```

This runs `docker compose up -d --build` and builds custom images for PHP and crawler.

### 3) Common local commands

```bash
make ps
make logs
make backend-shell
make crawler-shell
make mysql-shell
make redis-cli
```

### 4) Stop the stack

```bash
make down
```

## Notes

- Nginx serves the Laravel public folder from `apps/backend/public` on `http://localhost:8080` by default.
- PHP-FPM, crawler, MySQL, and Redis communicate through the internal Docker network.
- The crawler image installs `whatweb` from apt and Python crawler dependencies from pip.
- Business logic and crawler spiders are intentionally not scaffolded yet in this step.
