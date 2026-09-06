# Internal PageSpeed integration

PageSpeed collection runs in PHP/Laravel after website enrichment. Open a domain detail page and use **Measure performance** in **PageSpeed (internal)**. The section shows the latest measurements for the latest website audit. **Refresh measurements** creates new records while preserving older results in the database; active work is always reused.

By default, measure the successfully audited homepage and one category page on mobile. Contact/product/about pages are not measured in this milestone. Desktop is optional. A partial website audit can still supply its successful homepage/category pages. A running or failed audit cannot start measurements.

## Stored evidence

`page_speed_measurements` belongs to a specific `website_audit_pages` record and records:

- Requested/final URL, mobile/desktop strategy, Lighthouse version and actual test time.
- Performance score, lab metrics (FCP, LCP, Speed Index, TBT, CLS), up to ten diagnostic findings, and run warnings.
- Optional CrUX field data returned by PageSpeed, with URL/origin/other-URL scope and explicit availability. Field percentiles retain their API units; CLS field values use hundredths, while Lighthouse CLS is unitless.
- Queue state, attempt count, next attempt, lease, HTTP/error category, completion and expiry.
- A compact drafting-evidence object describing a measured lab concern and its limits. No observation is suggested when the score is good.

Lab and field measurements remain separate. Missing scores fail explicitly; missing individual metrics remain null. A genuine zero score is valid. The collector does not derive a Core Web Vitals pass/fail, ranking penalty, conversion loss, or lost-sales claim from a lab score. Configurable default ratings are poor below 50, needs improvement below 90, and good from 90.

Google's lab results may vary between runs. Google also states that real-user data will eventually be removed from the PageSpeed API; the collector tolerates its absence. A separate CrUX API collector is not included.

Raw responses, screenshots, resource lists, and provider error bodies are not persisted. There is no client-facing report, attachment, email generation, or sending. Later drafting should use only fresh, completed measurements and the supported observation, keeping the detailed evidence internal.

## Requests, budgets, and recovery

The client calls the fixed Google PageSpeed v5 endpoint with `category=performance` and an explicit device. It validates the target's public DNS address before submission and checks the returned requested/final URL and device. Google's browser controls its own navigation; this integration does not claim to pin or inspect Google's redirect hops. A cross-host final URL or HTTPS downgrade is rejected as evidence for this domain. Redirects from the Google API endpoint itself are disabled.

Each HTTP request has a maximum 60-second timeout and an eight-megabyte response-write limit. A measurement job has a 70-second worker timeout and an 85-second ownership lease. A database unique active key prevents duplicate work for the same audit page/device, and ownership checks prevent an old worker overwriting a newer lease.

Successful results are reused for seven days from the actual measurement timestamp. Final failures are held for 24 hours before automatic retry through a new measurement. These windows are configurable; explicit refresh bypasses expiry. Freshness is scoped to the audit page/device: a new website audit can request new measurements even if an older audit measured the same URL.

Transient HTTP/network/Lighthouse errors have at most three collection attempts, normally separated by 60 and 300 seconds. HTTP 429 and recognized Google 403 quota errors apply a shared cooldown; Retry-After is honored, bounded to one day. Daily-quota errors wait one day. Other 4xx responses fail without blind retries. Saved errors are generic and exclude provider bodies and query-string keys.

Default shared budgets reserve at most six requests per minute and 300 per 24-hour counter window, including retries. Each window starts with its first reservation; it is not a calendar-day counter or Google's own quota. Throttled jobs release back to the queue without consuming a collection attempt. Queue deliveries allow repeated rate-limit releases; actual attempts are capped in the measurement record. Unexpected queue exceptions are separately capped at three.

The batch command recovers active records untouched for five minutes whose lease and retry delay have expired. Timed-out jobs retain their attempt count and are recovered without refetching HTML or changing audit/contact data. Run the batch command periodically for this recovery behavior.

Use the same database or Redis cache store and cache prefix across all PageSpeed workers. `PAGESPEED_CACHE_STORE` defaults to `database`, independently of the application's general cache default. The store must support atomic locks; a process-local array cache would not enforce a shared production budget. Do not clear the budget cache as a routine operation because it resets request counters.

## Setup

1. In Google Cloud, enable the **PageSpeed Insights API** and create an API key. Restrict it to this API and, where practical, the worker's outbound server IP. Google documents keyless access, but recommends a key for automated usage. This integration does not require a paid third-party provider.
2. Set server environment variables (keep the key out of Git and shared request logs):

   ```dotenv
   PAGESPEED_ENABLED=true
   PAGESPEED_API_KEY=your-server-api-key
   PAGESPEED_QUEUE_CONNECTION=database
   PAGESPEED_CACHE_STORE=database
   PAGESPEED_DESKTOP=false
   PAGESPEED_SCHEDULED=false
   ```

3. From `apps/backend`, migrate, clear configuration, and start a dedicated worker under your process manager:

   ```sh
   php artisan migrate --force
   php artisan optimize:clear
   php artisan queue:work database --queue=pagespeed --timeout=70
   ```

   Use PHP with PCNTL for enforced worker timeouts and cURL for HTTP. The previous enrichment PR added PCNTL to the repository PHP image. Keep the queue connection's `retry_after` greater than 85 seconds; the existing default of 90 works. For Redis, change the queue/cache environment values and use `queue:work redis`. Restart workers after deployment/configuration changes. Start with one PageSpeed worker; the budget is shared if you add workers later.

4. Complete a website audit, then queue measurements manually:

   ```sh
   php artisan websites:pagespeed --domain-id=123
   php artisan websites:pagespeed --domain-id=123 --refresh
   php artisan websites:pagespeed --limit=25
   ```

   The batch limit counts measurements queued or recovered, not domains. The batch selects only the latest audit per domain and does not start or repeat website enrichment. Its mobile queue is filled before optional desktop work.

5. To automate collection/recovery, set `PAGESPEED_SCHEDULED=true` and ensure Laravel's regular `php artisan schedule:run` cron runs every minute. The PageSpeed batch command runs every five minutes. Website enrichment has its own independent schedule switch. Without the PageSpeed schedule, periodically run the batch command yourself to recover interrupted work.

Main tuning options live in `apps/backend/config/pagespeed.php`. Enabling desktop can double requests. Google's quotas are separate from local limits; inspect the API project's actual quota before increasing local budgets. Disabling `PAGESPEED_ENABLED` prevents new dispatch and marks pending work failed when its worker next handles it; existing saved results remain visible.

## Validation

Fixture-based tests cover request shape, metadata persistence, mobile/desktop separation, missing/zero scores, absent field data, origin fallback, URL/device mismatches, quota handling, streaming response limits, network errors and secret redaction, retry exhaustion, freshness/history, duplicate dispatch, leases/recovery, shared budgets, batch limits, and admin authorization. Existing website-audit/contact/classification regressions remain covered. No live Google requests or production deployment are part of this PR; validate one real domain after configuring the server key and worker.

Official references:

- [PageSpeed API setup and field-data deprecation notice](https://developers.google.com/speed/docs/insights/v5/get-started)
- [PageSpeed v5 request and response reference](https://developers.google.com/speed/docs/insights/v5/reference/pagespeedapi/runpagespeed)
- [Lab and field data in PageSpeed Insights](https://developers.google.com/speed/docs/insights/v5/about)
