<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\Domain;
use App\Models\PageClassification;
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
        $scan = $this->reader->read($classification);
        if ($scan['status'] === 'unavailable') {
            return; // Legacy classifications contain no contact-extraction evidence.
        }

        DB::transaction(function () use ($classification, $scan): void {
            // Serializes imports and manual changes for this domain.
            $domain = Domain::query()->lockForUpdate()->findOrFail($classification->domain_id);
            $observedAt = $classification->classified_at ?? $classification->created_at;
            $ids = [];
            foreach ($scan['emails'] as $evidence) {
                $contact = $domain->contacts()->firstOrNew(['email' => $evidence['email']]);
                $contact->suggested_purpose = $this->purposes->classify($evidence['email']);
                $contact->first_seen_at = $contact->first_seen_at?->min($observedAt) ?? $observedAt;
                $contact->last_seen_at = $contact->last_seen_at?->max($observedAt) ?? $observedAt;
                $contact->save();
                $ids[] = $contact->id;

                $source = $contact->sources()->firstOrNew(['url_hash' => hash('sha256', $evidence['url'])]);
                if ($source->last_seen_at === null || $observedAt->gte($source->last_seen_at)) {
                    $source->original_hint = $evidence['original_hint'];
                    $source->page_classification_id = $classification->id;
                }
                $source->url = $evidence['url'];
                $source->methods = array_values(array_unique([...($source->methods ?? []), ...$evidence['methods']]));
                $source->first_seen_at = $source->first_seen_at?->min($observedAt) ?? $observedAt;
                $source->last_seen_at = $source->last_seen_at?->max($observedAt) ?? $observedAt;
                $source->save();
            }

            $previous = $domain->contactsClassification;
            if ($previous !== null && ($previous->classified_at->gt($observedAt)
                || ($previous->classified_at->eq($observedAt) && $previous->id > $classification->id))) {
                return; // Historical imports must not replace the current selection.
            }
            $domain->forceFill(['contacts_classification_id' => $classification->id])->save();
            $this->selection->reconcileAutomatic($domain, array_unique($ids), $scan['status'] === 'collected');
        }, 3);
    }
}
