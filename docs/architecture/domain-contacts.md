# Domain contacts: operation and maintenance

The Contacts section lives in **Leads / Domains → open a domain**, below Page Classification. This stage stores and selects recipients for future drafting; it does not send messages or establish sending eligibility.

## Deploy and import existing evidence

From `apps/backend`, after deploying the code:

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan contacts:import
```

The import reads saved `page_classifications.classification_metadata.pages_scanned[].contacts`. It makes no website or external API requests, processes classifications in chunks of 100, and can be repeated. To inspect a small rollout first:

```sh
php artisan contacts:import --domain-id=123
```

Classifications created before the Python contact extractor was deployed may contain no contact evidence; import cannot reconstruct it. Those domains need a new `page_classification` crawl. New classifications received through the existing internal API automatically ingest contacts in the same database transaction. Domain discovery/homepage fetching alone does not invoke this stage.

## Selection behavior

- Exactly one suitable same-domain address in a complete imported scan: automatically selected.
- Multiple suitable addresses, only support/unknown/third-party addresses, or an incomplete scan: manual review required.
- Legal/privacy, recruitment, and no-reply purposes are excluded from selection by default. Review and correct the purpose if the hint is wrong.
- A radio selection is saved immediately, marks the contact reviewed, and changes the domain's selection mode to manual.
- Clearing a selection is also a manual decision. A future scan does not select another address automatically.
- Excluding a selected contact or changing its purpose to a blocked purpose clears the selection.
- Manual additions do not silently become primary or restore excluded contacts. Select an added email using its radio button.
- A manual primary selection survives future scans, including scans that no longer observe that address. Observation dates remain visible so the operator can assess freshness. Future sending must independently check fresh evidence, suppression, and recipient eligibility.
- Automatically selected contacts are reconsidered on each new scan; only addresses observed in that scan qualify. Incomplete scans clear an automatic selection.

## PHP ownership

`config/contacts.php` defines purpose labels, address-prefix patterns, automatic-selection eligibility, and blocked purposes. The first matching pattern wins. `ContactPurposeClassifier` applies those rules in Laravel; the Python crawler's original hint remains on the source record for reference.

`purpose_override` stores a manual correction separately from `suggested_purpose`. Imports update suggestions and observation dates without resetting overrides, review state, exclusions, or manual selection. After changing configured rules, clear/rebuild the application's config cache and rerun the import to refresh suggestions. Already selected manual contacts still require a fresh eligibility check before any future sending.

## Storage and concurrency

- `domain_contacts`: one normalized lowercase email per domain, suggested purpose, manual override, review state, manual-add flag, first/last observed dates.
- `domain_contact_sources`: one source URL per contact, validated HTTP(S) URL, discovery methods, original crawler hint, first/last observed dates, latest observation's classification reference. A URL hash provides a compact unique index.
- `domains.primary_contact_id`: the single primary contact pointer. Changes are scoped to the current domain.
- `domains.contact_selection_mode`: `automatic` or `manual`.
- `domains.contacts_classification_id`: newest imported contact scan, ordered by observation time and classification ID. Historical imports can extend source history without replacing current selection.

Imports and manual changes lock the domain row in a database transaction. Unique constraints prevent duplicate contacts/sources; classification persistence rolls back if contact ingestion fails. No external calls occur inside these transactions. New selection fields are guarded from generic domain mass assignment.

The UI is a nested Livewire component in the existing Filament infolist. It rechecks domain view/update authorization on server actions and scopes all supplied contact IDs to that domain. Contact rows are paginated; source details load when opened.

## Empty and incomplete results

No extraction metadata means **No contact extraction results yet**, not no address exists. A completed empty scan means **No published email found on the sampled pages**. Truncated/skipped extraction, invalid evidence, or sampled pages missing from results mean the scan is incomplete. The latest failed classification job is shown separately, retaining earlier evidence.

Contact-page/form links come from the latest classification metadata and are displayed only after HTTP(S)/host validation. These links are not automatically visited or submitted. There is no new dedicated enrichment job or audit-run table in this change.

## Verification

```sh
php artisan test --filter=DomainContactsTest
php artisan test --filter=PageClassificationControllerTest
```

Tests cover API ingestion, source deduplication, automatic/manual selection, exclusions, out-of-order/repeated imports, transaction rollback, missing/partial evidence, manual additions, rendered domain controls, and unauthorized/cross-domain Livewire actions.

The legacy crawl-trigger migration now uses SQLite's unquoted JSON extraction when running the repository's SQLite test configuration; its MySQL update expression is preserved. A migration test covers payload preservation and rollback. A broader suite run also exposed an existing failure in `ProcessPendingDomainsCommandTest` when it uses `pluck('crawl_payload->job_type')` on SQLite. That separate discovery test is outside this change.
