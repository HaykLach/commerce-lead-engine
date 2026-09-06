# PHP email drafting

The **Email drafts** section on a domain detail page generates and stores short English outreach drafts for the currently selected contact. It works without a PageSpeed API key or measurement: unavailable speed evidence is omitted. Sending is a separate, later milestone.

## What this version writes

The OpenAI adapter selects one or two supported findings and writes a short subject and opening. PHP inserts the selected observations verbatim, followed by FFP's configured positioning, the exact agreed call invitation, and Ruben Simonyan's signature. This constrained composition keeps the findings traceable while allowing a personalized opening.

The current findings catalog covers missing page titles, meta descriptions, main headings, and image alt attributes in the downloaded homepage/category/product HTML. Statements explicitly refer to the HTML checked; they do not claim to inspect JavaScript-rendered layouts or prove that the business is losing sales. A fresh successful mobile PageSpeed result with a score below the configured good threshold can supply a brief speed observation, provided it has no run warnings. A newer failed/queued measurement supersedes an older success for drafting eligibility.

The model receives the domain, approved FFP services, and canonical finding text/IDs. It does not receive the recipient email, raw HTML, page excerpts, PageSpeed scores, metrics, or reports. Website content is not an instruction source. The model has no tools and cannot choose a different recipient.

The application validates the returned structure, finding IDs, length, plain-text format, and common unsupported claims/report details in the subject/opening. These checks are not a complete semantic verifier. Review all generated wording before use. Manual edits are human-authored content and require the same review; saving never marks a draft approved or ready to send.

## Preconditions and review workflow

1. Select an eligible contact in the existing Contacts panel. An excluded or blocked-purpose contact cannot be used. An automatically selected eligible contact also qualifies; this is selection for drafting, not proof of consent or delivery eligibility.
2. Complete a fresh website audit. Completed and partial audits can supply successful pages. A failed, running, absent, or expired latest audit blocks drafting.
3. Use **Generate draft**. Identical input reuses an existing record, including a failed or blocked attempt. **Generate a new version** explicitly creates another record, preserving old drafts and edits. Active work is always reused.
4. Review the recipient, subject, body, and source findings. **Edit this draft** allows changes to the subject/body while preserving the call invitation and signature. The generated original remains stored separately. Concurrent edits use a revision check rather than overwriting a newer save.

No supported findings produces a blocked record and no OpenAI request. Missing PageSpeed alone does not block a draft with valid HTML findings. If PageSpeed is later configured, regenerate explicitly to use the new evidence; existing drafts are not silently rewritten.

The worker checks the selected recipient and evidence before generation and again before saving. A changed contact, newer audit, changed eligible measurement, or expired source blocks the old request. The admin also shows when an existing draft's sources are no longer current. Historical drafts are preserved and remain tied to their original recipient.

## Storage and execution

`outreach_drafts` stores the domain, audit/contact references, recipient snapshot, evidence and exact prompt snapshot, input hash, configured/returned model, prompt version, response ID, token usage, selected finding IDs, generated/current copy, edit revision, queue state, timestamps, and safe errors. Refused, incomplete, or invalid successful API responses retain returned usage metadata but do not publish an email body.

`DraftGenerator` is replaceable through Laravel's container; `OpenAiDraftGenerator` is the default binding. It uses the Responses API with strict structured output, no tools, `store=false`, a bounded output-token setting, a 60-second timeout, and a one-megabyte response-write cap. The key stays in server configuration and is not stored in the prompt or request records. `store=false` is not a claim of zero provider retention; consult your OpenAI project's data controls when enabling the integration.

There is one active draft per domain, enforced by a unique database key. Workers use a 70-second timeout, an 85-second ownership lease, and ownership checks on writes. HTTP 429 and 5xx responses may retry up to three collection attempts, with a 60/300-second backoff and bounded Retry-After handling. Authentication, insufficient quota, refusal, incomplete output, validation errors, and ambiguous connection failures do not automatically retry.

An interrupted `generating` record is marked failed during recovery rather than making another potentially billed call. Check provider usage before explicitly regenerating. A queued request lost before publication can be re-enqueued after five minutes. Both recovery paths run through the batch command.

Shared cache limits reserve at most three provider requests per minute and 100 per 24-hour counter window by default, including retries. The daily window starts at its first reservation. Rate-limited jobs release without using an attempt. This is a request budget, not a currency budget; model pricing and token usage determine cost. Set project spending limits separately. Workers must share the configured database/Redis cache and cache prefix.

Automatic batching creates at most one draft record per latest audit and selected contact. It does not automatically regenerate failures or edits, and does not redo website or PageSpeed collection. Explicit regeneration remains available after correcting configuration or collecting additional evidence.

## Configuration and rollout

The metered OpenAI adapter was part of the agreed drafting milestone. Discovery remains free of paid APIs. This PR does not invoke the paid API or change `AGENTS.md`.

Set server environment variables after choosing a model available to your OpenAI project that supports Responses API strict structured output:

```dotenv
OUTREACH_DRAFTING_ENABLED=true
OPENAI_API_KEY=your-server-project-key
OUTREACH_DRAFT_MODEL=your-chosen-model-id
OUTREACH_DRAFT_QUEUE_CONNECTION=database
OUTREACH_DRAFT_CACHE_STORE=database
OUTREACH_DRAFTING_SCHEDULED=false
```

There is intentionally no default model or automatically enabled billing. The PageSpeed key is separate and optional. Keep real keys out of Git, client-side code, and shared HTTP logs.

From `apps/backend`:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan queue:work database --queue=outreach-drafts --timeout=70
php artisan outreach:draft --domain-id=123
php artisan outreach:draft --domain-id=123 --regenerate
php artisan outreach:draft --limit=25
```

Use a process manager and PHP with PCNTL/cURL. Keep the queue connection's `retry_after` above 85 seconds; the repository default is 90. For Redis, configure the queue/cache stores and run `queue:work redis`. Restart workers after deployment or configuration changes.

For automatic batching/recovery, set `OUTREACH_DRAFTING_SCHEDULED=true` and run Laravel's regular `schedule:run` cron every minute. The drafting command is scheduled every five minutes. Without that switch, run the batch command periodically to recover interrupted work. Both enable switches default to false.

Edit `apps/backend/config/outreach.php` to maintain FFP services, positioning, CTA, signature, model settings, request budget, and prompt version. Bump the prompt version when changing generation behavior. The exact prompt and branding text used by each request remain stored with that record.

## Validation and next step

Tests use mocked HTTP with no live OpenAI calls. They cover drafting without PageSpeed, source eligibility, no-findings behavior, output validation/refusal, usage retention, source changes during generation, request budgets, retry limits, ambiguous-request recovery, history/deduplication, secret redaction, source JSON key ordering, and editor authorization/concurrency. Real model quality and project availability still need a small configured draft-only trial.

Delivery, approvals for sending, suppression checks, campaign limits, SMTP handling, and mailbox synchronization belong to the next milestone. This migration does not add an outbox or a send action.

Official reference: [OpenAI structured outputs and refusal/incomplete handling](https://developers.openai.com/api/docs/guides/structured-outputs).
