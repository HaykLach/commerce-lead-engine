# Monorepo Structure

## Top-level modules

- `apps/backend`: Laravel API and orchestration layer
- `apps/crawler`: Scrapy crawler and analysis pipeline
- `packages/shared-config`: versioned shared scoring/fingerprint config
- `infra`: local and deployment support artifacts
- `docs`: architecture and operational documentation

## Concept-to-module mapping

- Domain discovery: `apps/crawler/crawler/spiders`
- Platform detection: `apps/crawler/crawler/fingerprints` + `infra/whatweb`
- Page classification: `apps/crawler/crawler/classifiers`
- Lead scoring: `apps/crawler/crawler/scoring` + `packages/shared-config/scoring`
