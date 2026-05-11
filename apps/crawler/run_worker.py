import time
import json
import os
import logging
from types import SimpleNamespace

import requests

from lead_crawler.services.ecommerce_verifier import EcommerceVerifier
from lead_crawler.services.homepage_fetch_service import HomepageFetchService
from lead_crawler.services.whatweb_runner_service import WhatWebRunnerService
from lead_crawler.fingerprint.rule_engine import FingerprintRuleEngine
from lead_crawler.services.page_classification_service import PageClassificationService
from lead_crawler.services.domain_normalizer import DomainNormalizer
from lead_crawler.services.search_seed_discovery_service import SearchSeedDiscoveryService
from lead_crawler.services.directory_discovery_service import DirectoryDiscoveryService
from lead_crawler.services.expansion_discovery_service import ExpansionDiscoveryService
from lead_crawler.services.common_crawl_athena_backend import CommonCrawlAthenaBackend, CommonCrawlAthenaConfig
from lead_crawler.services.common_crawl_discovery_service import CommonCrawlDiscoveryService
from lead_crawler.services.common_crawl_domain_filter import CommonCrawlDomainFilter
from lead_crawler.services.common_crawl_duckdb_backend import CommonCrawlDuckDbBackend, CommonCrawlDuckDbConfig
from lead_crawler.services.common_crawl_index_api_backend import CommonCrawlIndexApiBackend, CommonCrawlIndexApiConfig
from lead_crawler.services.common_crawl_importer import CommonCrawlImporter
from lead_crawler.services.common_crawl_local_index_backend import CommonCrawlLocalIndexBackend, CommonCrawlLocalIndexConfig
from lead_crawler.services.common_crawl_url_pattern_builder import CommonCrawlUrlPatternBuilder
from lead_crawler.services.sme_tranco_filter import SmeTrancoFilter

LARAVEL_API_BASE = os.getenv("LARAVEL_API_BASE", "http://nginx/api/v1/internal")
WORKER_LOG_LEVEL = os.getenv("WORKER_LOG_LEVEL", "INFO").upper()
TLD_COUNTRY_HINTS = {
    # DACH
    "de": "DE",
    "at": "AT",
    "ch": "CH",
    # Benelux / Nordics
    "nl": "NL",
    "se": "SE",
    # Middle East
    "ae": "AE",
    # North America
    "us": "US",
    # Other EU
    "fr": "FR",
    "it": "IT",
    "es": "ES",
    "pl": "PL",
}
SUPPORTED_LOCAL_INDEX_COUNTRIES = {"de", "nl", "fr", "it", "es", "ch", "us"}


def _log_response(label, response):
    print(f"[Worker] {label} -> status={response.status_code}")
    if response.status_code != 204:
        body = response.text.strip()
        if body:
            print(f"[Worker] {label} -> body={body}")


def _api_request(method, path, **kwargs):
    url = f"{LARAVEL_API_BASE}{path}"
    print(f"[Worker] {method.upper()} {url}")
    response = requests.request(method=method.upper(), url=url, timeout=30, **kwargs)
    _log_response(path, response)
    return response


def get_next_job():
    try:
        res = _api_request("get", "/crawl-jobs/next")

        if res.status_code == 204:
            print("[Worker] No queued crawl jobs available")
            return None

        if res.status_code == 200:
            payload = res.json()
            job = payload.get("data")

            if not job:
                print("[Worker] Invalid response: missing data key")
                return None

            return job

        print(f"[Worker] Unexpected response while fetching next job: {res.status_code}")

    except Exception as e:
        print(f"[Worker] Error fetching job: {e}")

    return None


def mark_job_started(job_id):
    _api_request("post", f"/crawl-jobs/{job_id}/start")


def mark_job_completed(job_id, summary):
    _api_request("post", f"/crawl-jobs/{job_id}/complete", json={
        "summary": summary
    })


def mark_job_failed(job_id, error):
    _api_request("post", f"/crawl-jobs/{job_id}/fail", json={
        "error": str(error)
    })


def normalize_domain(domain):
    normalizer = DomainNormalizer()
    return normalizer.normalize(domain) or ""


def _extract_country_hint_from_whatweb(whatweb_plugins):
    for plugin in whatweb_plugins or []:
        if not isinstance(plugin, dict):
            continue

        key = str(plugin.get("name") or plugin.get("plugin") or "").lower()
        if key != "country":
            continue

        version = plugin.get("version")
        if isinstance(version, list) and version:
            hint = str(version[0]).strip().upper()
        elif isinstance(version, str):
            hint = version.strip().upper()
        else:
            hint = None

        if hint and len(hint) == 2:
            return hint

    return None


def infer_country(domain, whatweb_plugins):
    whatweb_hint = _extract_country_hint_from_whatweb(whatweb_plugins)
    if whatweb_hint:
        return whatweb_hint, {"source": "whatweb"}

    normalized = normalize_domain(domain)
    parts = normalized.split(".")
    tld = parts[-1] if parts else ""
    country = TLD_COUNTRY_HINTS.get(tld)
    if country:
        return country, {"source": "tld", "tld": tld}

    return None, None



def persist_fingerprint_record(summary, domain_snapshot):
    fingerprint = normalize_fingerprint_result(summary.get("fingerprint") or {})
    whatweb_payload = summary.get("whatweb") or {}

    payload = {
        "domain_id": domain_snapshot.get("id"),
        "domain": summary.get("domain"),
        "platform": fingerprint.get("platform") or "unknown",
        "confidence": fingerprint.get("confidence"),
        "frontend_stack": fingerprint.get("frontend_stack") or [],
        "signals": fingerprint.get("signals") or [],
        "raw_payload": fingerprint,
        "whatweb_payload": whatweb_payload,
        "detected_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }

    response = _api_request("post", "/fingerprints", json=payload)
    if response.status_code not in (200, 201):
        raise RuntimeError(f"Fingerprint creation failed with status {response.status_code}")


def build_fingerprint_input(result, whatweb_result):
    scripts = result.get("scripts", [])
    stylesheets = result.get("stylesheets", [])
    links = result.get("links", [])
    cookies = result.get("cookies", {})
    headers = result.get("headers", {})
    final_url = result.get("final_url", "")
    html = result.get("html", "")
    meta = result.get("meta", {})

    whatweb_ns = SimpleNamespace(
        target_url=whatweb_result.target_url,
        plugins=whatweb_result.plugins,
        raw_payload=whatweb_result.raw_payload,
        error=whatweb_result.error,
    )

    return SimpleNamespace(
        html=html,
        headers=headers,
        cookies=cookies,
        scripts=scripts,
        script_urls=scripts,
        stylesheets=stylesheets,
        stylesheet_urls=stylesheets,
        links=links,
        link_urls=links,
        final_url=final_url,
        url=final_url,
        meta=meta,
        whatweb=whatweb_ns,
    )


def process_homepage_fetch(job):
    payload = job.get("crawl_payload") or {}
    if isinstance(payload, str):
        payload = json.loads(payload)

    domain = payload.get("domain")
    if not domain:
        raise ValueError("crawl_payload.domain is missing")

    print(f"[Worker] Fetching {domain}")

    fetcher = HomepageFetchService()
    result = fetcher.fetch(domain)

    print(f"[Worker] fetch result type: {type(result)}")

    if result is None:
        raise ValueError("HomepageFetchService.fetch() returned None")
    if not isinstance(result, dict):
        raise ValueError(f"Unexpected fetch result type: {type(result)}")

    # Filter out uninteresting sites before running expensive detection.
    verifier = EcommerceVerifier()
    verification = verifier.verify(
        domain=domain,
        html=result.get("html", ""),
        links=result.get("links") or [],
        scripts=result.get("scripts") or [],
    )

    print(
        f"[Worker] Site filter: is_acceptable={verification.is_acceptable} "
        f"reason={verification.reason} signals={verification.signals} "
        f"disqualifiers={verification.disqualifiers}"
    )

    if not verification.is_acceptable:
        # Persist the domain with a disqualification marker and skip fingerprinting.
        _api_request("post", "/domains/upsert", json={
            "domain": domain,
            "normalized_domain": normalize_domain(domain),
            "last_crawled_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "metadata": {
                "site_acceptable": False,
                "disqualification_reason": verification.reason,
                "disqualifiers": verification.disqualifiers,
                "final_url": result.get("final_url"),
                "status_code": result.get("status_code"),
            },
        })
        return {
            "domain": domain,
            "site_acceptable": False,
            "disqualification_reason": verification.reason,
            "disqualifiers": verification.disqualifiers,
            "skipped_fingerprinting": True,
        }

    whatweb = WhatWebRunnerService()
    whatweb_result = whatweb.scan(domain)

    engine = FingerprintRuleEngine()
    fingerprint_input = build_fingerprint_input(result, whatweb_result)
    fingerprint = normalize_fingerprint_result(engine.detect(fingerprint_input))

    country, country_hint_meta = infer_country(domain, (whatweb_result.plugins or []))
    metadata = {
        "site_acceptable": True,
        "checkout_signals": verification.signals,
        "final_url": result.get("final_url"),
        "status_code": result.get("status_code"),
        "whatweb_country_hint": _extract_country_hint_from_whatweb(whatweb_result.plugins or []),
        "crawl_hints": {
            "script_count": len(result.get("scripts") or []),
            "stylesheet_count": len(result.get("stylesheets") or []),
            "link_count": len(result.get("links") or []),
        },
    }
    if country_hint_meta:
        metadata["country_hint"] = country_hint_meta

    domain_payload = {
        "domain": domain,
        "normalized_domain": normalize_domain(domain),
        "platform": fingerprint.get("platform"),
        "confidence": fingerprint.get("confidence"),
        "country": country,
        "last_crawled_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "metadata": metadata,
    }

    response = _api_request("post", "/domains/upsert", json=domain_payload)
    if response.status_code not in (200, 201):
        raise RuntimeError(f"Domain upsert failed with status {response.status_code}")

    domain_snapshot = (response.json() if response.text else {}).get("data") or {}

    summary = {
        "domain": domain,
        "site_acceptable": True,
        "final_url": result.get("final_url"),
        "status_code": result.get("status_code"),
        "fingerprint": fingerprint,
        "whatweb": {
            "target_url": whatweb_result.target_url,
            "plugins": whatweb_result.plugins,
            "error": whatweb_result.error,
        },
    }

    persist_fingerprint_record(summary, domain_snapshot)

    if payload.get("enqueue_page_classification", False):
        enqueue_page_classification_job(job, domain_snapshot, domain)

    summary["domain_id"] = domain_snapshot.get("id")
    summary["enqueued_page_classification"] = payload.get("enqueue_page_classification", False)

    return summary



def persist_page_classification(job, domain_snapshot, classification_result):
    payload = {
        "domain_id": domain_snapshot.get("id"),
        "domain": domain_snapshot.get("normalized_domain") or classification_result.domain,
        "crawl_job_id": job.get("id"),
        "product_page_found": classification_result.product_page_found,
        "category_page_found": classification_result.category_page_found,
        "cart_page_found": classification_result.cart_page_found,
        "checkout_page_found": classification_result.checkout_page_found,
        "sample_product_url": classification_result.sample_product_url,
        "sample_category_url": classification_result.sample_category_url,
        "sample_cart_url": classification_result.sample_cart_url,
        "sample_checkout_url": classification_result.sample_checkout_url,
        "product_count_guess": classification_result.product_count_guess,
        "product_count_bucket": classification_result.product_count_bucket,
        "classification_metadata": classification_result.classification_metadata,
        "classified_at": classification_result.classified_at,
    }

    response = _api_request("post", "/page-classifications", json=payload)
    if response.status_code not in (200, 201):
        raise RuntimeError(f"Page classification persistence failed with status {response.status_code}")


def enqueue_page_classification_job(job, domain_snapshot, domain):
    payload = {
        "domain_id": domain_snapshot.get("id"),
        "trigger_type": job.get("trigger_type") or "manual",
        "recrawl_of_job_id": job.get("id"),
        "crawl_payload": {
            "job_type": "page_classification",
            "domain": domain,
        },
    }

    response = _api_request("post", "/crawl-jobs", json=payload)
    if response.status_code not in (200, 201):
        raise RuntimeError(f"Page classification job enqueue failed with status {response.status_code}")


def process_page_classification(job):
    payload = job.get("crawl_payload") or {}
    if isinstance(payload, str):
        payload = json.loads(payload)

    domain = payload.get("domain")
    if not domain:
        raise ValueError("crawl_payload.domain is missing")

    domain_snapshot = payload.get("domain_snapshot") or {
        "id": job.get("domain_id"),
        "normalized_domain": normalize_domain(domain),
    }

    classifier = PageClassificationService()
    classification = classifier.classify_domain(domain=domain, max_pages=int(payload.get("max_pages", 12)))

    persist_page_classification(job, domain_snapshot, classification)

    return {
        "domain": domain,
        "job_type": "page_classification",
        "product_page_found": classification.product_page_found,
        "category_page_found": classification.category_page_found,
        "cart_page_found": classification.cart_page_found,
        "checkout_page_found": classification.checkout_page_found,
        "sample_product_url": classification.sample_product_url,
        "sample_category_url": classification.sample_category_url,
        "sample_cart_url": classification.sample_cart_url,
        "sample_checkout_url": classification.sample_checkout_url,
        "product_count_guess": classification.product_count_guess,
        "product_count_bucket": classification.product_count_bucket,
    }


def normalize_fingerprint_result(fingerprint):
    if fingerprint is None:
        return None

    if isinstance(fingerprint, dict):
        return {str(k): _to_json_serializable(v) for k, v in fingerprint.items()}

    result = {}

    for attr in [
        "platform",
        "confidence",
        "signals",
        "frontend_stack",
        "raw",
        "raw_payload",
        "matched_rules",
        "reasons",
    ]:
        if hasattr(fingerprint, attr):
            value = getattr(fingerprint, attr)

            result[attr] = _to_json_serializable(value)

    if not result:
        result = _to_json_serializable(fingerprint.__dict__.copy()) if hasattr(fingerprint, "__dict__") else {
            "value": str(fingerprint)
        }

    return result


def _to_json_serializable(value):
    if isinstance(value, (str, int, float, bool)) or value is None:
        return value

    if isinstance(value, dict):
        return {str(k): _to_json_serializable(v) for k, v in value.items()}

    if isinstance(value, (list, tuple, set)):
        return [_to_json_serializable(item) for item in value]

    if hasattr(value, "__dict__"):
        return _to_json_serializable(value.__dict__)

    return str(value)


def ingest_discovered_domain(candidate, payload):
    source_context = {
        "keyword_seed": candidate.get("keyword_seed"),
        "source_url": candidate.get("source_url"),
        "discovery_job_type": payload.get("job_type"),
    }
    source_context.update(candidate.get("source_context") or {})

    response = _api_request("post", "/discovered-domains/ingest", json={
        "domain": candidate["domain"],
        "normalized_domain": candidate["domain"],
        "source_type": candidate["source_type"],
        "source_name": payload.get("source_name") or f'{candidate["source_type"]}_discovery',
        "source_reference": candidate.get("source_url"),
        "source_context": {k: v for k, v in source_context.items() if v is not None},
        "priority_homepage_fetch": int(payload.get("priority_homepage_fetch", 3)),
        "priority_page_classification": int(payload.get("priority_page_classification", 5)),
        "enqueue_homepage_fetch": payload.get("enqueue_homepage_fetch", True),
        "enqueue_page_classification": payload.get("enqueue_page_classification", False),
    })

    if response.status_code not in (200, 201):
        raise RuntimeError(f"Discovered domain ingest failed with status {response.status_code}")

    body = response.json() if response.text else {}
    return body.get("data") or {}


def process_domain_discovery_search_seed(job):
    payload = job.get("crawl_payload") or {}
    if isinstance(payload, str):
        payload = json.loads(payload)

    keywords = payload.get("keywords") or []
    countries = payload.get("countries") or []
    limit = int(payload.get("limit", 100))

    service = SearchSeedDiscoveryService()
    discovered = service.discover(keywords=keywords, countries=countries, limit=limit)

    ingested = []
    for candidate in discovered:
        candidate_payload = {
            "domain": candidate.domain,
            "source_type": candidate.source_type,
            "keyword_seed": candidate.keyword_seed,
            "source_url": candidate.source_url,
        }
        ingest_discovered_domain(candidate_payload, payload)
        ingested.append(candidate_payload)

    return {
        "job_type": "domain_discovery_search_seed",
        "discovered_count": len(discovered),
        "ingested_count": len(ingested),
        "domains": ingested,
    }


def process_domain_discovery_directory(job):
    payload = job.get("crawl_payload") or {}
    if isinstance(payload, str):
        payload = json.loads(payload)

    directory_urls = payload.get("directory_urls") or []
    limit = int(payload.get("limit", 100))

    service = DirectoryDiscoveryService()
    discovered = service.discover(directory_urls=directory_urls, limit=limit)

    ingested = []
    for candidate in discovered:
        candidate_payload = {
            "domain": candidate.domain,
            "source_type": candidate.source_type,
            "keyword_seed": None,
            "source_url": candidate.source_url,
        }
        ingest_discovered_domain(candidate_payload, payload)
        ingested.append(candidate_payload)

    return {
        "job_type": "domain_discovery_directory",
        "discovered_count": len(discovered),
        "ingested_count": len(ingested),
        "domains": ingested,
    }


def process_domain_discovery_expansion(job):
    payload = job.get("crawl_payload") or {}
    if isinstance(payload, str):
        payload = json.loads(payload)

    domains = payload.get("domains") or []
    limit = int(payload.get("limit", 50))

    service = ExpansionDiscoveryService()
    discovered = service.discover(domains=domains, limit=limit)

    ingested = []
    for candidate in discovered:
        candidate_payload = {
            "domain": candidate.domain,
            "source_type": candidate.source_type,
            "keyword_seed": candidate.keyword_seed,
            "source_url": candidate.source_url,
        }
        ingest_discovered_domain(candidate_payload, payload)
        ingested.append(candidate_payload)

    return {
        "job_type": "domain_discovery_expansion",
        "discovered_count": len(discovered),
        "ingested_count": len(ingested),
        "domains": ingested,
    }

def _build_common_crawl_backend(payload):
    backend_name = str(payload.get("backend", "duckdb")).strip().lower()

    if backend_name == "duckdb":
        config = CommonCrawlDuckDbConfig(
                        crawls=payload.get("cc_crawls"),
                        dataset_path=payload.get("duckdb_dataset_path"),
                        cc_files_per_crawl=int(payload.get("cc_files_per_crawl", 3)),
        )
        return CommonCrawlDuckDbBackend(config=config), backend_name

    if backend_name == "athena":
        config = CommonCrawlAthenaConfig(
            database=str(payload.get("athena_database", "commoncrawl")),
            table=str(payload.get("athena_table", "ccindex")),
            output_location=str(payload.get("athena_output_location", "s3://change-me/athena-results/")),
            region_name=str(payload.get("athena_region", "us-east-1")),
            workgroup=payload.get("athena_workgroup"),
        )
        return CommonCrawlAthenaBackend(config=config), backend_name

    if backend_name == "cc_index_api":
        raw_page_size = int(payload.get("cc_page_size", 100))
        config = CommonCrawlIndexApiConfig(
            crawls=payload.get("cc_crawls") or None,
            tld_targets=payload.get("cc_tld_targets") or None,
            requests_per_crawl=int(payload.get("cc_requests_per_crawl", 3)),
            page_size=min(raw_page_size, 100),
            request_delay_seconds=float(payload.get("cc_delay", 1.0)),
        )
        return CommonCrawlIndexApiBackend(config=config), backend_name

    if backend_name == "local_index":
        config = CommonCrawlLocalIndexConfig(
            min_sme_score=float(payload.get("min_sme_score", 0.0)),
        )
        return CommonCrawlLocalIndexBackend(config=config), backend_name

    raise ValueError(f"Unsupported Common Crawl backend: {backend_name}")


def process_domain_discovery_common_crawl(job):
    payload = job.get("crawl_payload") or {}
    if isinstance(payload, str):
        payload = json.loads(payload)

    patterns = CommonCrawlUrlPatternBuilder.normalize_patterns(payload.get("patterns"))
    countries = payload.get("countries") or []
    niches = payload.get("niches") or []
    limit = int(payload.get("limit", 500))

    backend, backend_name = _build_common_crawl_backend(payload)
    print(
        "[Worker] CommonCrawl config:",
        json.dumps(
            {
                "backend": backend_name,
                "patterns": patterns,
                "countries": countries,
                "niches": niches,
                "limit": limit,
                "cc_crawls": payload.get("cc_crawls"),
                "cc_tld_targets": payload.get("cc_tld_targets"),
                "cc_requests_per_crawl": payload.get("cc_requests_per_crawl"),
                "cc_page_size": payload.get("cc_page_size"),
                "cc_delay": payload.get("cc_delay"),
                "min_sme_score": payload.get("min_sme_score"),
                "use_tranco_filter": payload.get("use_tranco_filter"),
            },
            default=str,
        ),
    )

    tranco_filter = None
    if payload.get("use_tranco_filter"):
        tranco_filter = SmeTrancoFilter(
            int(payload.get("tranco_top_n", 50000)),
            payload.get("tranco_cache_path"),
        )

    domain_filter = CommonCrawlDomainFilter(
        set(payload.get("domain_denylist", [])),
        float(payload.get("min_sme_score", 0.0)),
        tranco_filter,
    )
    service = CommonCrawlDiscoveryService(backend=backend, domain_filter=domain_filter)
    discovered = service.discover(
        patterns=patterns,
        limit=limit,
        countries=countries,
        niches=niches,
    )
    print(f"[Worker] CommonCrawl discovered candidate domains before ingest: {len(discovered)}")
    if discovered:
        sample = [candidate.domain for candidate in discovered[:10]]
        print(f"[Worker] CommonCrawl sample domains: {sample}")

    ingested = []
    for candidate in discovered:
        candidate_payload = {
            "domain": candidate.domain,
            "source_type": candidate.source_type,
            "keyword_seed": None,
            "source_url": candidate.source_url,
            "source_context": candidate.source_context,
        }
        ingest_discovered_domain(candidate_payload, payload)
        ingested.append(candidate_payload)

    return {
        "job_type": "domain_discovery_common_crawl",
        "backend": backend_name,
        "patterns": patterns,
        "discovered_count": len(discovered),
        "ingested_count": len(ingested),
        "domains": ingested,
    }


def process_common_crawl_import(job):
    payload = job.get("crawl_payload") or {}
    if isinstance(payload, str):
        payload = json.loads(payload)

    importer = CommonCrawlImporter()
    return importer.run_import(payload)


def process_domain_discovery_local_index(job):
    payload = job.get("crawl_payload") if isinstance(job, dict) else {}
    if not payload and isinstance(job, dict) and job.get("job_type"):
        payload = job
    if isinstance(payload, str):
        payload = json.loads(payload)
    if not isinstance(payload, dict):
        raise ValueError("crawl_payload must be an object")

    countries = payload.get("countries") or []
    if len(countries) != 1:
        raise ValueError("Exactly one country must be provided for domain_discovery_local_index jobs")

    country = str(countries[0]).strip().lower()
    if country not in SUPPORTED_LOCAL_INDEX_COUNTRIES:
        raise ValueError(f"Unsupported country: {country}")

    limit = int(payload.get("limit", 500))
    min_sme_score = float(payload.get("min_sme_score", 0.0))
    exclude_existing_domains = bool(payload.get("exclude_existing_domains", True))
    payload["backend"] = "local_index"

    print(
        "[Worker] Local index config:"
        f" country={country} limit={limit} min_sme_score={min_sme_score} "
        f"exclude_existing_domains={str(exclude_existing_domains).lower()}"
    )
    print("[Worker] Local index query starting...")

    backend = CommonCrawlLocalIndexBackend(CommonCrawlLocalIndexConfig(min_sme_score=min_sme_score))
    rows = backend.fetch_domains_for_ingest(
        country=country,
        min_sme_score=min_sme_score,
        limit=limit,
        exclude_existing_domains=exclude_existing_domains,
    )

    print(f"[Worker] Local index rows selected: {len(rows)}")
    if rows:
        sample_domains = [str(row.get("domain")) for row in rows[:10]]
        print(f"[Worker] Local index sample domains: {sample_domains}")
    else:
        debug_counts = backend.local_index_debug_counts(
            country=country,
            min_sme_score=min_sme_score,
            exclude_existing_domains=exclude_existing_domains,
        )
        print(
            "[Worker] Local index debug counts:"
            f" country_total={debug_counts['country_total']}"
            f" country_with_min_score_total={debug_counts['country_with_min_score_total']}"
            f" exclude_existing_domains={str(exclude_existing_domains).lower()}"
            f" country_with_min_score_excluding_existing_total="
            f"{debug_counts['country_with_min_score_excluding_existing_total']}"
        )

    ingested = []
    for row in rows:
        candidate_payload = {
            "domain": row["domain"],
            "source_type": "common_crawl_local_index",
            "keyword_seed": None,
            "source_url": row.get("source_url") or f"https://{row['domain']}",
            "source_context": {
                "backend": "local_index",
                "common_crawl_domain_id": row.get("id"),
                "country": row.get("country"),
                "tld": row.get("tld"),
                "ecommerce_score": row.get("ecommerce_score"),
                "matched_patterns": row.get("matched_patterns"),
                "country_signals": row.get("country_signals"),
                "crawl_id": row.get("crawl_id"),
            },
        }
        ingested_domain = ingest_discovered_domain(candidate_payload, payload)
        ingested.append(ingested_domain or candidate_payload)

    print(f"[Worker] Local index ingested domains: {len(ingested)}")

    return {
        "job_type": "domain_discovery_local_index",
        "backend": "local_index",
        "country": country,
        "min_sme_score": min_sme_score,
        "limit": limit,
        "exclude_existing_domains": exclude_existing_domains,
        "selected_count": len(rows),
        "ingested_count": len(ingested),
        "domains": ingested,
    }



def run():
    print("🚀 Worker started...")

    while True:
        job = get_next_job()

        if not job:
            print("[Worker] No job found, sleeping...")
            time.sleep(3)
            continue

        job_id = job["id"]

        try:
            trigger_type = job.get("trigger_type")
            payload = job.get("crawl_payload") or {}
            if isinstance(payload, str):
                payload = json.loads(payload)

            job_type = payload.get("job_type")

            print(f"[Worker] Processing job {job_id}")
            print(f"[Worker] Job metadata: trigger_type={trigger_type}, job_type={job_type}")

            if not job_type:
                raise ValueError("crawl_payload.job_type is missing")

            mark_job_started(job_id)

            if job_type == "homepage_fetch":
                result = process_homepage_fetch(job)
            elif job_type == "page_classification":
                result = process_page_classification(job)
            elif job_type == "domain_discovery_search_seed":
                result = process_domain_discovery_search_seed(job)
            elif job_type == "domain_discovery_directory":
                result = process_domain_discovery_directory(job)
            elif job_type == "domain_discovery_expansion":
                result = process_domain_discovery_expansion(job)
            elif job_type == "domain_discovery_common_crawl":
                result = process_domain_discovery_common_crawl(job)
            elif job_type == "common_crawl_import":
                result = process_common_crawl_import(job)
            elif job_type == "domain_discovery_local_index":
                result = process_domain_discovery_local_index(job)
            else:
                raise ValueError(f"Unknown job type: {job_type}")

            mark_job_completed(job_id, result)
            print(f"[Worker] Completed job {job_id}")

        except Exception as e:
            print(f"[Worker] Failed job {job_id}: {e}")
            mark_job_failed(job_id, str(e))


if __name__ == "__main__":
    logging.basicConfig(
        level=getattr(logging, WORKER_LOG_LEVEL, logging.INFO),
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    run()
