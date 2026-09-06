<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Models\Domain;
use App\Services\Contacts\ContactEvidence;
use App\Services\Contacts\ContactSelectionService;

class DraftEvidenceBuilder
{
    public function __construct(private readonly ContactSelectionService $contacts) {}

    public function build(Domain $domain): array
    {
        $contact = $domain->contacts()->find($domain->primary_contact_id);
        if ($contact === null || ! $this->contacts->selectable($contact) || ContactEvidence::email($contact->email) === null) {
            throw new DraftException('no_recipient', 'Select an eligible contact email before drafting.');
        }
        $audit = $domain->latestWebsiteAudit()->first();
        if ($audit === null || ! in_array($audit->status, ['completed', 'partial'], true) || ! $audit->expires_at?->isFuture()) {
            throw new DraftException('no_fresh_audit', 'Complete a fresh website audit before drafting.');
        }
        $facts = [];
        $expires = $audit->expires_at->copy();
        foreach ($audit->pages()->where('status', 'completed')->whereIn('kind', ['homepage', 'category', 'product'])->with('pageSpeedMeasurements')->get() as $page) {
            $metadata = $page->evidence['metadata'] ?? [];
            $name = $page->kind === 'homepage' ? 'homepage' : $page->kind.' page';
            $source = ['page_id' => $page->id, 'source_url' => $page->final_url ?? $page->url, 'observed_at' => $page->fetched_at?->toIso8601String()];
            $add = function (string $type, string $text, array $extra = []) use (&$facts, $page, $source): void {
                $facts[] = ['id' => 'page-'.$page->id.'-'.$type, 'type' => $type, 'text' => $text] + $extra + $source;
            };
            if (array_key_exists('title', $metadata) && $metadata['title'] === '') {
                $add('missing_title', 'The '.$name.' HTML we checked has no page title, leaving its purpose less clear to search engines.');
            }
            if (array_key_exists('description', $metadata) && $metadata['description'] === '') {
                $add('missing_description', 'The '.$name.' HTML we checked has no meta description, leaving less control over its search preview.');
            }
            if (isset($metadata['h1']) && is_array($metadata['h1']) && $metadata['h1'] === []) {
                $add('missing_heading', 'The '.$name.' HTML we checked has no main heading, leaving its content structure less clear.');
            }
            if (is_int($metadata['images_without_alt_attribute'] ?? null) && $metadata['images_without_alt_attribute'] > 0) {
                $add('missing_alt', 'Some images in the '.$name.' HTML lack alt attributes, which can make meaningful images harder to understand with assistive technology.');
            }
            // Use only the newest mobile result, never an older success behind a failed refresh.
            $speed = $page->pageSpeedMeasurements->where('strategy', 'mobile')->sortByDesc('id')->first();
            if ($speed?->status === 'completed' && $speed->expires_at?->isFuture() && $speed->performance_score !== null
                && $speed->performance_score < (int) config('pagespeed.good_score_from')
                && empty($speed->result['lab']['warnings'])) {
                $add('speed', 'Our mobile test flagged room to improve '.$name.' loading speed. Faster pages can improve the shopping experience and support SEO.',
                    ['measurement_id' => $speed->id, 'observed_at' => $speed->measured_at?->toIso8601String()]);
                if ($speed->expires_at->lt($expires)) {
                    $expires = $speed->expires_at->copy();
                }
            }
        }

        return ['version' => 1, 'domain' => $domain->normalized_domain, 'audit_id' => $audit->id, 'contact_id' => $contact->id,
            'recipient_email' => $contact->email, 'expires_at' => $expires->toIso8601String(), 'facts' => $facts];
    }
}
