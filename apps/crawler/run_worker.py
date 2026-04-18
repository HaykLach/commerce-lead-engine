import time
import json
import os
import re
from types import SimpleNamespace
from urllib.parse import urlparse

import requests

from lead_crawler.services.homepage_fetch_service import HomepageFetchService
from lead_crawler.services.whatweb_runner_service import WhatWebRunnerService
from lead_crawler.fingerprint.rule_engine import FingerprintRuleEngine

LARAVEL_API_BASE = os.getenv("LARAVEL_API_BASE", "http://nginx/api/v1/internal")
TLD_COUNTRY_HINTS = {
    "de": "DE",
    "fr": "FR",
    "nl": "NL",
    "it": "IT",
    "es": "ES",
    "pl": "PL",
    "se": "SE",
}
NICHE_KEYWORDS = {
    "fashion": ["apparel", "clothing", "shoes", "sneakers", "menswear", "womenswear", "accessories", "footwear"],
    "tech": ["electronics", "gadgets", "devices", "laptop", "phone accessories", "hardware", "smart home"],
    "b2b": ["wholesale", "distributor", "reseller", "dealer", "trade", "bulk order", "rfq", "request quote", "moq"],
}


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
    candidate = (domain or "").strip().lower()
    parsed = urlparse(candidate if "://" in candidate else f"https://{candidate}")
    host = (parsed.netloc or parsed.path).strip().lower().strip("/")

    if host.startswith("www."):
        host = host[4:]

    return host


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


def infer_niche(html, meta):
    html_content = html or ""
    title_match = re.search(r"<title[^>]*>(.*?)</title>", html_content, flags=re.IGNORECASE | re.DOTALL)
    title = title_match.group(1).strip() if title_match else ""
    description = meta.get("description", "") if isinstance(meta, dict) else ""
    body_text = re.sub(r"<[^>]+>", " ", html_content)
    body_text = re.sub(r"\s+", " ", body_text).strip()[:5000]
    corpus = f"{title} {description} {body_text}".lower()

    scores = {}
    for niche, keywords in NICHE_KEYWORDS.items():
        scores[niche] = sum(corpus.count(keyword.lower()) for keyword in keywords)

    top_niche = max(scores, key=scores.get) if scores else None
    if not top_niche or scores[top_niche] == 0:
        return None, scores

    return top_niche, scores


def persist_domain_snapshot(summary, fetch_result):
    fingerprint = summary.get("fingerprint") or {}
    whatweb = summary.get("whatweb") or {}
    metadata = {
        "final_url": summary.get("final_url"),
        "status_code": summary.get("status_code"),
        "whatweb_country_hint": _extract_country_hint_from_whatweb(whatweb.get("plugins") or []),
        "crawl_hints": {
            "script_count": len(fetch_result.get("scripts") or []),
            "stylesheet_count": len(fetch_result.get("stylesheets") or []),
            "link_count": len(fetch_result.get("links") or []),
        },
    }

    country, country_hint_meta = infer_country(summary.get("domain"), whatweb.get("plugins") or [])
    if country_hint_meta:
        metadata["country_hint"] = country_hint_meta

    niche, niche_scores = infer_niche(fetch_result.get("html"), fetch_result.get("meta"))
    metadata["niche_scores"] = niche_scores

    payload = {
        "domain": summary.get("domain"),
        "normalized_domain": normalize_domain(summary.get("domain")),
        "platform": fingerprint.get("platform"),
        "confidence": fingerprint.get("confidence"),
        "country": country,
        "niche": niche,
        "last_crawled_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "metadata": metadata,
    }

    response = _api_request("post", "/domains/upsert", json=payload)
    if response.status_code not in (200, 201):
        raise RuntimeError(f"Domain upsert failed with status {response.status_code}")

    body = response.json() if response.text else {}
    return body.get("data") or {}


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

    whatweb = WhatWebRunnerService()
    whatweb_result = whatweb.scan(domain)

    engine = FingerprintRuleEngine()
    fingerprint_input = build_fingerprint_input(result, whatweb_result)
    fingerprint = normalize_fingerprint_result(engine.detect(fingerprint_input))

    summary = {
        "domain": domain,
        "final_url": result.get("final_url"),
        "status_code": result.get("status_code"),
        "fingerprint": fingerprint,
        "whatweb": {
            "target_url": whatweb_result.target_url,
            "plugins": whatweb_result.plugins,
            "error": whatweb_result.error,
        },
    }

    domain_snapshot = persist_domain_snapshot(summary, result)
    persist_fingerprint_record(summary, domain_snapshot)

    return summary

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
            else:
                raise ValueError(f"Unknown job type: {job_type}")

            mark_job_completed(job_id, result)
            print(f"[Worker] Completed job {job_id}")

        except Exception as e:
            print(f"[Worker] Failed job {job_id}: {e}")
            mark_job_failed(job_id, str(e))


if __name__ == "__main__":
    run()
