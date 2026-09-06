<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\Domain;
use App\Models\PageClassification;
use App\Models\WebsiteAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ContactIngestionService
{
    public function __construct(
        private readonly ContactScanReader $reader,
        private readonly ContactPurposeClassifier $purposes,
        private readonly ContactSelectionService $selection,
    ) {}

    public function ingest(PageClassification $classification): void
    {
        $this->ingestScan($classification);
    }

    public function ingestAudit(WebsiteAudit $audit): void
    {
        $this->ingestScan($audit);
    }

    private function ingestScan(PageClassification|WebsiteAudit $classification): void
    {
        $scan = $this->reader->read($classification);
        if ($scan['status'] === 'unavailable') {
            return; // Legacy classifications contain no contact-extraction evidence.
        }

        DB::transaction(function () use ($classification, $scan): void {
            // Serializes imports and manual changes for this domain.
            $domain = Domain::query()->lockForUpdate()->findOrFail($classification->domain_id);
            $isAudit = $classification instanceof WebsiteAudit;
            $observedAt = ($isAudit ? $classification->finished_at : $classification->classified_at) ?? $classification->created_at;
            $ids = [];
            foreach ($scan['emails'] as $evidence) {
                $pageObservedAt = $evidence['observed_at'] ? Carbon::parse($evidence['observed_at']) : $observedAt;
                $contact = $domain->contacts()->firstOrNew(['email' => $evidence['email']]);
                $contact->suggested_purpose = $this->purposes->classify($evidence['email']);
                $contact->first_seen_at = $contact->first_seen_at?->min($pageObservedAt) ?? $pageObservedAt;
                $contact->last_seen_at = $contact->last_seen_at?->max($pageObservedAt) ?? $pageObservedAt;
                $contact->save();
                $ids[] = $contact->id;

                $source = $contact->sources()->firstOrNew(['url_hash' => hash('sha256', $evidence['url'])]);
                if ($source->last_seen_at === null || $pageObservedAt->gte($source->last_seen_at)) {
                    $source->original_hint = $evidence['original_hint'];
                    $source->page_classification_id = $isAudit ? null : $classification->id;
                    $source->website_audit_id = $isAudit ? $classification->id : null;
                }
                $source->url = $evidence['url'];
                $source->methods = array_values(array_unique([...($source->methods ?? []), ...$evidence['methods']]));
                $source->first_seen_at = $source->first_seen_at?->min($pageObservedAt) ?? $pageObservedAt;
                $source->last_seen_at = $source->last_seen_at?->max($pageObservedAt) ?? $pageObservedAt;
                $source->save();
            }

            $previous = $domain->contactsAudit ?? $domain->contactsClassification;
            $previousAt = ($previous instanceof WebsiteAudit ? $previous->finished_at : $previous?->classified_at) ?? $previous?->created_at;
            if ($previousAt !== null && ($previousAt->gt($observedAt)
                || ($previousAt->eq($observedAt) && (($previous instanceof WebsiteAudit && ! $isAudit)
                    || (get_class($previous) === get_class($classification) && $previous->id > $classification->id))))) {
                return; // Historical imports must not replace the current selection.
            }
            $domain->forceFill(['contacts_classification_id' => $isAudit ? null : $classification->id, 'contacts_audit_id' => $isAudit ? $classification->id : null])->save();
            $this->selection->reconcileAutomatic($domain, array_unique($ids), $scan['status'] === 'collected');
        }, 3);
    }
}
