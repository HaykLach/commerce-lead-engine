# Internal Crawler ↔ Backend API (v1)

This API layer is designed for trusted internal services (crawler workers, orchestrators, backfill jobs). Responses are intentionally compact and schema-driven.

Base path: `/api/v1/internal`

---

## 1) Upsert discovered domains

**POST** `/domains/upsert`

### Example request

```json
{
  "domain": "https://example-store.com",
  "status": "queued",
  "platform": "shopify",
  "confidence": 88.5,
  "country": "US",
  "niche": "fashion",
  "business_model": "d2c",
  "metadata": {
    "discovered_by": "sitemap",
    "seed_url": "https://example-store.com/sitemap.xml"
  },
  "first_seen_at": "2026-04-17T11:00:00Z",
  "last_seen_at": "2026-04-17T11:01:30Z"
}
```

### Example response (200)

```json
{
  "data": {
    "id": 18,
    "domain": "https://example-store.com",
    "status": "queued",
    "metadata": {
      "discovered_by": "sitemap",
      "seed_url": "https://example-store.com/sitemap.xml"
    },
    "created_at": "2026-04-17T11:01:30.000000Z",
    "updated_at": "2026-04-17T11:01:30.000000Z"
  }
}
```

---

## 2) Store fingerprints

**POST** `/fingerprints`

### Example request

```json
{
  "name": "shopify_theme_asset",
  "platform": "shopify",
  "version": "2",
  "priority": 50,
  "confidence_weight": 12.5,
  "rules": {
    "contains": ["cdn.shopify.com/s/files"],
    "headers": ["x-shopify-stage"]
  },
  "metadata": {
    "source": "custom_rule_pack_v1"
  },
  "is_active": true
}
```

### Example response (201)

```json
{
  "data": {
    "id": 4,
    "name": "shopify_theme_asset",
    "platform": "shopify",
    "version": "2",
    "priority": 50,
    "confidence_weight": "12.50",
    "rules": {
      "contains": ["cdn.shopify.com/s/files"],
      "headers": ["x-shopify-stage"]
    },
    "metadata": {
      "source": "custom_rule_pack_v1"
    },
    "is_active": true,
    "created_at": "2026-04-17T11:04:22.000000Z",
    "updated_at": "2026-04-17T11:04:22.000000Z"
  }
}
```

---

## 3) Store page classifications

**POST** `/page-classifications`

### Example request

```json
{
  "domain_id": 18,
  "crawl_job_id": 31,
  "url": "https://example-store.com/products/cotton-tee",
  "canonical_url": "https://example-store.com/products/cotton-tee",
  "page_type": "product",
  "confidence": 94.2,
  "signals": {
    "price_node": true,
    "add_to_cart": true
  },
  "features": {
    "schema_product": true,
    "variant_selector": true
  }
}
```

### Example response (201)

```json
{
  "data": {
    "id": 203,
    "domain_id": 18,
    "crawl_job_id": 31,
    "url": "https://example-store.com/products/cotton-tee",
    "canonical_url": "https://example-store.com/products/cotton-tee",
    "page_type": "product",
    "confidence": "94.20",
    "signals": {
      "price_node": true,
      "add_to_cart": true
    },
    "features": {
      "schema_product": true,
      "variant_selector": true
    },
    "created_at": "2026-04-17T11:06:00.000000Z",
    "updated_at": "2026-04-17T11:06:00.000000Z"
  }
}
```

---

## 4) Store domain metrics

**POST** `/domain-metrics`

### Example request

```json
{
  "domain_id": 18,
  "crawl_job_id": 31,
  "platform": "shopify",
  "confidence": 91.5,
  "country": "US",
  "niche": "fashion",
  "business_model": "d2c",
  "pages_crawled": 122,
  "product_pages": 58,
  "collection_pages": 11,
  "blog_pages": 20,
  "contact_pages": 1,
  "has_cart": true,
  "has_checkout": true,
  "raw_signals": {
    "framework": "liquid",
    "apps_detected": 16
  },
  "signal_summary": {
    "seo_gap": "medium",
    "plugin_bloat": "high"
  },
  "measured_at": "2026-04-17T11:10:00Z"
}
```

### Example response (201)

```json
{
  "data": {
    "id": 77,
    "domain_id": 18,
    "crawl_job_id": 31,
    "platform": "shopify",
    "confidence": "91.50",
    "country": "US",
    "niche": "fashion",
    "business_model": "d2c",
    "pages_crawled": 122,
    "product_pages": 58,
    "collection_pages": 11,
    "blog_pages": 20,
    "contact_pages": 1,
    "has_cart": true,
    "has_checkout": true,
    "raw_signals": {
      "framework": "liquid",
      "apps_detected": 16
    },
    "signal_summary": {
      "seo_gap": "medium",
      "plugin_bloat": "high"
    },
    "measured_at": "2026-04-17T11:10:00.000000Z",
    "created_at": "2026-04-17T11:10:01.000000Z",
    "updated_at": "2026-04-17T11:10:01.000000Z"
  }
}
```

---

## 5) Store lead scores

**POST** `/lead-scores`

### Example request

```json
{
  "domain_id": 18,
  "crawl_job_id": 31,
  "domain_metric_id": 77,
  "score_config_id": 2,
  "opportunity_score": 83.4,
  "grade": "hot",
  "score_breakdown": {
    "plugin_script_bloat": 28,
    "seo_gap": 22,
    "platform_fit": 16,
    "ecommerce_maturity": 17.4
  },
  "score_reasons": [
    {
      "key": "high_plugin_count",
      "label": "High plugin/script bloat",
      "weight": 0.28
    }
  ],
  "version": "v1",
  "computed_at": "2026-04-17T11:12:00Z"
}
```

### Example response (201)

```json
{
  "data": {
    "id": 90,
    "domain_id": 18,
    "crawl_job_id": 31,
    "domain_metric_id": 77,
    "score_config_id": 2,
    "opportunity_score": "83.40",
    "grade": "hot",
    "score_breakdown": {
      "plugin_script_bloat": 28,
      "seo_gap": 22,
      "platform_fit": 16,
      "ecommerce_maturity": 17.4
    },
    "score_reasons": [
      {
        "key": "high_plugin_count",
        "label": "High plugin/script bloat",
        "weight": 0.28
      }
    ],
    "version": "v1",
    "computed_at": "2026-04-17T11:12:00.000000Z",
    "created_at": "2026-04-17T11:12:00.000000Z",
    "updated_at": "2026-04-17T11:12:00.000000Z"
  }
}
```

---

## 6) Create crawl jobs

**POST** `/crawl-jobs`

### Example request

```json
{
  "domain_id": 18,
  "status": "queued",
  "trigger_type": "scheduled",
  "priority": 7,
  "attempt": 1,
  "max_attempts": 3,
  "scheduled_at": "2026-04-17T11:20:00Z",
  "crawl_payload": {
    "depth": 2,
    "max_urls": 300,
    "respect_robots": true
  }
}
```

### Example response (201)

```json
{
  "data": {
    "id": 32,
    "domain_id": 18,
    "recrawl_of_job_id": null,
    "status": "queued",
    "trigger_type": "scheduled",
    "priority": 7,
    "attempt": 1,
    "max_attempts": 3,
    "scheduled_at": "2026-04-17T11:20:00.000000Z",
    "started_at": null,
    "finished_at": null,
    "next_crawl_at": null,
    "failure_reason": null,
    "crawl_payload": {
      "depth": 2,
      "max_urls": 300,
      "respect_robots": true
    },
    "crawl_summary": null,
    "created_at": "2026-04-17T11:19:55.000000Z",
    "updated_at": "2026-04-17T11:19:55.000000Z"
  }
}
```

---

## 7) List leads with filters

**GET** `/leads`

### Query filters

- `domain` (string contains on normalized domain)
- `grade` (`hot|warm|cold`)
- `platform` (domain platform)
- `country` (ISO-2)
- `niche`
- `business_model`
- `min_score`
- `max_score`
- `per_page` (1..100)

### Example request

`GET /api/v1/internal/leads?grade=hot&platform=shopify&min_score=75&per_page=2`

### Example response (200)

```json
{
  "data": [
    {
      "lead_score_id": 90,
      "opportunity_score": "83.40",
      "grade": "hot",
      "computed_at": "2026-04-17T11:12:00.000000Z",
      "version": "v1",
      "domain": {
        "id": 18,
        "domain": "https://example-store.com",
        "normalized_domain": "example-store.com",
        "status": "processed",
        "platform": "shopify",
        "country": "US",
        "niche": "fashion",
        "business_model": "d2c"
      },
      "metric": {
        "id": 77,
        "pages_crawled": 122,
        "product_pages": 58,
        "collection_pages": 11,
        "has_cart": true,
        "has_checkout": true
      },
      "score_breakdown": {
        "plugin_script_bloat": 28,
        "seo_gap": 22,
        "platform_fit": 16,
        "ecommerce_maturity": 17.4
      },
      "score_reasons": [
        {
          "key": "high_plugin_count",
          "label": "High plugin/script bloat",
          "weight": 0.28
        }
      ]
    }
  ],
  "links": {
    "first": "http://localhost/api/v1/internal/leads?page=1",
    "last": "http://localhost/api/v1/internal/leads?page=20",
    "prev": null,
    "next": "http://localhost/api/v1/internal/leads?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 20,
    "path": "http://localhost/api/v1/internal/leads",
    "per_page": 2,
    "to": 2,
    "total": 40
  }
}
```
