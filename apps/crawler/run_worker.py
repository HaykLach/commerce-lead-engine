import time
import json
import os
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
            return res.json()
        print(f"[Worker] Unexpected response while fetching next job: {res.status_code}")
    except Exception as e:
        print("Error fetching job:", e)
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


def process_homepage_fetch(job):
    payload = job.get("crawl_payload") or {}
    if isinstance(payload, str):
        payload = json.loads(payload)

    domain = payload.get("domain")

    print(f"[Worker] Fetching {domain}")

    fetcher = HomepageFetchService()
    result = fetcher.fetch(domain)

    # run whatweb
    whatweb = WhatWebRunnerService()
    whatweb_result = whatweb.run(domain)

    # fingerprint
    engine = FingerprintRuleEngine()
    fingerprint = engine.detect({
        "html": result.get("html"),
        "headers": result.get("headers"),
        "scripts": result.get("scripts"),
        "whatweb": whatweb_result,
    })

    return {
        "domain": domain,
        "fingerprint": fingerprint
    }


def run():
    print("🚀 Worker started...")

    while True:
        job = get_next_job()

        if not job:
            time.sleep(3)
            continue

        job_id = job["id"]

        try:
            print(f"[Worker] Processing job {job_id}")

            mark_job_started(job_id)

            trigger_type = job.get("trigger_type")
            payload = job.get("crawl_payload") or {}
            if isinstance(payload, str):
                payload = json.loads(payload)
            job_type = payload.get("job_type")

            print(
                f"[Worker] Job metadata: trigger_type={trigger_type}, job_type={job_type}"
            )

            if job_type == "homepage_fetch":
                result = process_homepage_fetch(job)
            else:
                print(f"Unknown job type: {job_type}")
                result = {}

            mark_job_completed(job_id, result)

            print(f"[Worker] Completed job {job_id}")

        except Exception as e:
            print(f"[Worker] Failed job {job_id}: {e}")
            mark_job_failed(job_id, str(e))


if __name__ == "__main__":
    run()
