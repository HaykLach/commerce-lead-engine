import time
import json
import os
from types import SimpleNamespace

import requests

from lead_crawler.services.homepage_fetch_service import HomepageFetchService
from lead_crawler.services.whatweb_runner_service import WhatWebRunnerService
from lead_crawler.fingerprint.rule_engine import FingerprintRuleEngine

LARAVEL_API_BASE = os.getenv("LARAVEL_API_BASE", "http://nginx/api/v1/internal")


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

    return {
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

def normalize_fingerprint_result(fingerprint):
    if fingerprint is None:
        return None

    if isinstance(fingerprint, dict):
        return fingerprint

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

            if isinstance(value, set):
                value = list(value)

            result[attr] = value

    if not result:
        result = fingerprint.__dict__.copy() if hasattr(fingerprint, "__dict__") else {
            "value": str(fingerprint)
        }

    return result

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