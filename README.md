# commerce-lead-engine

Monorepo for an ecommerce lead generation system with:

- Laravel backend API/admin service
- Scrapy crawler for domain discovery and page analysis
- Modular lead-scoring configuration
- MySQL, Redis, and WhatWeb-powered detection support

## Monorepo layout

```text
apps/
  backend/                # Laravel service (API, orchestration, lead persistence)
  crawler/                # Scrapy project (discovery, detection, classification)
packages/
  shared-config/          # Cross-service scoring + fingerprint configuration
infra/
  docker/                 # Compose and local container definitions
  mysql/                  # DB initialization helpers
  redis/                  # Redis defaults
  whatweb/                # WhatWeb profiles and wrappers
  queues/                 # Queue/process supervisor templates
docs/
  architecture/           # System design docs
  runbooks/               # Operational playbooks
  adr/                    # Architecture decision records
.github/
  workflows/              # CI pipelines
```

## Key design principles

- No paid APIs.
- Custom fingerprint detection for platform identification.
- Configurable scoring logic.
- Modular architecture between crawling, detection, and scoring.
