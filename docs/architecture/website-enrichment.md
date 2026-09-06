# PHP website enrichment

Website enrichment now runs in Laravel, independently of Python discovery and ecommerce classification. It stores real audit evidence without changing discovery scores or creating synthetic `PageClassification` records.

## Admin workflow

Open a domain detail page and use **Analyze website** in the **Website audit** section. The section shows queue status, coverage, per-page results, and failures. **Run a fresh audit** bypasses cached results; an active audit is always reused.

Completed page evidence feeds the existing Contacts section. Source URLs, discovery methods, and actual page observation times are retained. Manual contact choices and exclusions are preserved. Failed or truncated scans remain distinguishable from a complete scan with no emails.

## Collection and storage

- `website_audits` stores status, freshness, coverage, worker leases, and the contact-scan summary.
- `website_audit_pages` stores request/final URLs, attempts, HTTP status, errors, and extracted evidence. Raw HTML is not persisted.
- Each audit fetches at most six pages: homepage and at most one contact, category, product, impressum, and about page. Known classification sample URLs and saved contact URLs are reused. Relevant links found on successfully fetched pages can fill remaining slots.
- Metadata includes title, description, language, canonical URL, headings, viewport presence, image counts, script count, and a bounded text excerpt. These observations do not establish ranking penalties or lost sales.
- PHP extracts published emails from mailto To addresses, visible HTML text, and Organization/Store JSON-LD. It records contact-form candidates and source links. It does not submit forms or collect hidden field values or mailto body/cc/bcc parameters.

Only the domain's host and its `www` equivalent are allowed. URLs, public DNS addresses, and redirects are validated before requests; cURL pins the validated address. Private/reserved addresses, cross-host redirects, HTTPS downgrades, and proxy bypasses are blocked. Requests have an eight-second page deadline, at most three redirects, a one-megabyte decompressed HTML cap, bounded headers, and one-second pacing between pages by default. DNS lookup duration also depends on the operating system; the queue worker provides the overall attempt timeout.

There is no JavaScript execution, CSS visibility evaluation, obfuscated-email decoding, or mailbox verification. Coverage describes the selected pages, not every page on a website.

## Queue behavior

One active audit per domain is enforced by a database unique constraint. Successful audits are reused for seven days; partial/failed results wait 24 hours before automatic recollection. Explicit refresh is available in the admin and CLI.

Each page has up to three attempts. Transient failures retry with queue backoff (30 and 120 seconds), while successful pages are reused with their original observation time. Permanent failures produce partial/failed results. Workers use a 70-second timeout and an 85-second ownership lease. Recovery is bounded to five processing attempts per audit.

The batch command recovers active work untouched for five minutes when its lease has expired. This covers interrupted workers and a failure between audit persistence and queue publication. Run the batch command periodically for this recovery behavior.

Configuration lives in `apps/backend/config/enrichment.php`; extraction purpose hints remain in the existing PHP contacts configuration. Enrichment requires a database or Redis queue and uses the dedicated `website-enrichment` queue.

## Rollout

The PHP worker needs cURL, DOM, mbstring, and PCNTL. The repository PHP image now includes PCNTL; rebuild that image when using Docker. A Hostinger deployment needs a worker process or scheduled worker execution supported by the hosting plan.

From `apps/backend`, apply the migration and clear cached configuration:

```sh
php artisan migrate --force
php artisan optimize:clear
```

Run a dedicated worker under your process manager:

```sh
php artisan queue:work database --queue=website-enrichment --timeout=70 --tries=3
```

The default enrichment connection is `database`, regardless of the application's general queue default. For Redis, set `WEBSITE_ENRICHMENT_QUEUE_CONNECTION=redis` and use `queue:work redis`. Keep that connection's `retry_after` greater than 70 seconds (the existing default is 90 seconds), and restart workers after deploying code/configuration.

Queue a single domain, explicitly refresh it, or queue a batch of accepted ecommerce domains:

```sh
php artisan websites:enrich --domain-id=123
php artisan websites:enrich --domain-id=123 --refresh
php artisan websites:enrich --limit=25
```

Automatic batching is disabled by default. Set `WEBSITE_ENRICHMENT_SCHEDULED=true` to run the batch command every five minutes through Laravel's scheduler. Ensure the normal `php artisan schedule:run` cron runs every minute. With automatic batching disabled, run the batch command manually when recovery is needed.

## Validation and next milestone

The focused enrichment/contact/classification regression suite covers extraction, bounded page discovery, freshness, duplicate dispatch, worker leases, retry reuse/exhaustion, source timestamps, manual selections, admin access, unsafe addresses/URLs, redirect validation, and DNS rebinding. Tests use fixtures and mocked HTTP/DNS; no live websites or paid APIs are invoked.

PageSpeed measurements are the next collector. They will have their own retry state and remain internal evidence for a brief, supported email observation. AI drafting and message delivery are later milestones.
