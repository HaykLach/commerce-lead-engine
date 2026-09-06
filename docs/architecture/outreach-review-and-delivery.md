# Daily outreach review and scheduled delivery

The admin navigation now includes **Daily outreach reports** at `/admin/outreach-reports`. Select an Armenia calendar date to see draft subjects, recipients, delivery states and website analysis activity. Open each draft to review its source evidence and edit its subject/body using the existing domain editor. The domain detail page offers the same actions.

## Review flow

1. The existing enrichment, PageSpeed and drafting jobs process work throughout the day. They still require their own configuration and workers. This feature does not enable them automatically.
2. At **22:00 Asia/Yerevan (18:00 UTC)** the scheduler records the daily summary and, if configured, queues a Telegram notification containing counts and an authenticated report-page link. No recipient addresses or email content go to Telegram.
3. Review each email, save edits, choose **Send at (Armenia time)**, and select **Approve and schedule**. Unsaved edits cannot be approved. Saving alone never schedules delivery.
4. A minute-based dispatcher reads due approved records and publishes Laravel queue jobs. The worker checks approval, timing, content revision, selected contact and fresh source evidence immediately before reserving the SMTP handoff.
5. Pending messages can be rescheduled or cancelled. Cancellation preserves the approved snapshot and returns the draft to editing. A new approval creates a new snapshot. Sending/accepted/uncertain attempts cannot be cancelled or automatically repeated.

Times entered in the browser are interpreted as Asia/Yerevan regardless of the browser or server location. Storage is UTC; keep the application/database timezone at UTC. Schedules must be in the future, within 30 days, and before the evidence expires. Refreshing the analysis or changing/excluding the selected contact can block an already scheduled message; cancel it and review a fresh draft.

The selected time is the earliest permitted send time. With healthy cron/workers, due messages enter the queue within a minute. Queue load, downtime and rate limits can delay them. The default cap is **100 SMTP attempts per rolling 24 hours**, with at least **60 seconds between attempts**, shared across workers. Several messages scheduled for the same minute are spread out; this is not a guarantee of exact-second delivery.

The report groups records by their creation date in Armenia. Choose earlier dates to review older drafts. Its tables show live status, while the stored daily summary is a snapshot. Pending work and failures are explicitly reported: a summary at 22:00 is not a guarantee that external APIs or every queued analysis finished successfully. Missing OpenAI configuration is also explicit. Maintain enough worker capacity and feed the daily workload early enough to finish by the review deadline.

## Data and reliability

- `outreach_messages` is the durable outbox: approver/time, draft revision, frozen recipient/sender/subject/body, UTC schedule, stable Message-ID, SMTP state and safe errors.
- The unique active domain key permits one pending or attempted initial outreach per domain. Cancellation releases the key; accepted and uncertain attempts retain it. Automated follow-ups are outside this milestone.
- `outreach_delivery_locks` serializes quota reservations across workers. Locks are released before network I/O. SMTP uses a dedicated transport and a 15-second socket timeout, with a 60-second job timeout. Use PHP PCNTL for worker timeouts and a persistent database or Redis queue with `retry_after > 60` (the default 90 works).
- A queued record lost before enqueue becomes eligible again after five minutes. Duplicate delivery jobs cannot claim the same scheduled record twice. A worker interrupted during SMTP handoff becomes `unknown` after five minutes and is never blindly retried. SMTP cannot provide exactly-once delivery; a timeout may happen after acceptance.
- `accepted` means the SMTP server accepted the message, not that it reached the inbox. `unknown` requires mailbox/provider-log investigation. There is deliberately no resend button for uncertain outcomes.
- `daily_outreach_reports.report_date` is unique. Atomic notification claims prevent duplicate sends. The scheduler checks from 22:00 through 23:59 so a short evening outage can recover. Longer outages require the dated command below. Failed/uncertain Telegram responses are visible on the page and are not automatically repeated. Check the chat before any operational reconciliation.
- Selecting a website contact does not establish consent. Hostinger currently allows solicited messages and disallows unsolicited email to recipients who did not opt in: [Hostinger email policy](https://www.hostinger.com/support/1583510-is-mass-mailing-supported-at-hostinger/). Enable SMTP only for recipients eligible under the mailbox provider's rules. Existing contact exclusion blocks subsequent attempts. Automated opt-out processing, bounce/reply synchronization and follow-up campaigns are not included here.

## Configuration

All outbound switches default to false. Set real secrets only in server environment configuration:

```dotenv
APP_URL=https://your-admin-domain.example
OUTREACH_DELIVERY_QUEUE_CONNECTION=database
OUTREACH_SENDING_ENABLED=false
OUTREACH_TELEGRAM_ENABLED=false
TELEGRAM_BOT_TOKEN=
TELEGRAM_REPORT_CHAT_ID=
MAIL_FROM_ADDRESS=your-domain-mailbox
MAIL_FROM_NAME="Ruben Simonyan"
MAIL_SCHEME=smtps
MAIL_HOST=your-account-smtp-host
MAIL_PORT=465
MAIL_USERNAME=your-domain-mailbox
MAIL_PASSWORD=your-mailbox-password
```

Use the SMTP settings provided for your actual mailbox; the values above are placeholders. The dedicated `outreach` mailer reads these SMTP variables and never uses `MAIL_MAILER=log`, failover, or global recipient overrides. Telegram uses the bot token and explicit destination chat ID, independently of the OpenAI and Google keys. The bot must already be allowed to post in that chat. [Telegram sendMessage reference](https://core.telegram.org/bots/api#sendmessage).

From `apps/backend` after deployment:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

Run supervised workers (use `redis` instead of `database` if configured):

```sh
php artisan queue:work database --queue=outreach-reports --timeout=30 --tries=1
php artisan queue:work database --queue=outreach-delivery --timeout=60 --tries=1
```

Keep the existing enrichment, PageSpeed and drafting workers running separately so a large analysis backlog cannot delay the report or mail queue. Laravel's standard `schedule:run` cron must run every minute. Confirm your hosting plan supports the required CLI worker/process lifetime; a configured SMTP mailbox alone does not run queues. A stopped scheduler or worker prevents timely delivery.

Use the existing authenticated admin access policy. The current repository's plain User model is allowed by Filament only in a local environment; production requires an explicit `FilamentUser::canAccessPanel()` implementation for authorized administrators. This feature does not change or bypass that existing panel restriction. Do not set a production application to local just to bypass it.

Operational commands:

```sh
php artisan outreach:report --date=2026-09-06
php artisan outreach:send-due
```

The first command creates/reuses the snapshot and queues a notification only when Telegram is enabled and configured. Re-running a sent/failed/unknown report does not resend it. The second queues only due approved messages and only while sending is enabled. Both are covered by the scheduler.

Configure and test the report/review flow first with sending disabled. Then enable Telegram; enable SMTP separately when ready. Restart workers after configuration changes. Pausing sending leaves existing scheduled records pending; re-enabling may process overdue records, subject to the same eligibility checks and limits. Cancel anything you no longer intend to send before re-enabling.

## Validation

Feature tests use fake queues and mocked HTTP/SMTP. They cover Armenia day boundaries and 22:00 scheduling, saved-edit approval, authorization, frozen snapshots, cancellation/rescheduling, no early or disabled sending, source/contact changes, quota deferral, duplicate and lost jobs, SMTP ambiguity, Telegram deduplication and credential redaction, and the real SMTP adapter's explicit envelope/content assembly. No live recipient or Telegram chat is contacted by the test suite.
