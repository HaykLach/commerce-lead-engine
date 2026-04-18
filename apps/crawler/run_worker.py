import time
import json
import requests

from lead_crawler.services.homepage_fetch_service import HomepageFetchService
from lead_crawler.services.whatweb_runner_service import WhatWebRunnerService
from lead_crawler.fingerprint.rule_engine import FingerprintRuleEngine

LARAVEL_API = "http://nginx/internal"  # IMPORTANT (see below)

def get_next_job():
    try:
        res = requests.get(f"{LARAVEL_API}/jobs/next")
        if res.status_code == 200:
            return res.json()
    except Exception as e:
        print("Error fetching job:", e)
    return None


def mark_job_started(job_id):
    requests.post(f"{LARAVEL_API}/jobs/{job_id}/start")


def mark_job_completed(job_id, summary):
    requests.post(f"{LARAVEL_API}/jobs/{job_id}/complete", json={
        "summary": summary
    })


def mark_job_failed(job_id, error):
    requests.post(f"{LARAVEL_API}/jobs/{job_id}/fail", json={
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

            if job["trigger_type"] == "homepage_fetch":
                result = process_homepage_fetch(job)
            else:
                print(f"Unknown job type: {job['trigger_type']}")
                result = {}

            mark_job_completed(job_id, result)

            print(f"[Worker] Completed job {job_id}")

        except Exception as e:
            print(f"[Worker] Failed job {job_id}: {e}")
            mark_job_failed(job_id, str(e))


if __name__ == "__main__":
    run()