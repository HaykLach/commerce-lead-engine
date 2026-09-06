<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\PageClassification;

class ContactScanReader
{
    public function read(?PageClassification $classification): array
    {
        $result = ['status' => 'unavailable', 'emails' => [], 'links' => [], 'scanned_at' => $classification?->classified_at];
        $metadata = $classification?->classification_metadata ?? [];
        $pages = $metadata['pages_scanned'] ?? [];
        if (! is_array($pages) || $pages === []) {
            return $result;
        }
        $maxPages = (int) config('contacts.import.max_pages', 100);
        $maxEmails = (int) config('contacts.import.max_emails_per_page', 50);
        $partial = count($pages) > $maxPages;
        $found = false;
        $sampled = $metadata['sampled_urls'] ?? [];
        if (is_array($sampled) && count($sampled) > count($pages)) {
            $partial = true;
        }

        foreach (array_slice($pages, 0, $maxPages) as $page) {
            if (! is_array($page) || ! is_array($page['contacts'] ?? null)) {
                $partial = true;

                continue;
            }
            $found = true;
            $contacts = $page['contacts'];
            $pageUrl = ContactEvidence::url($page['final_url'] ?? $page['url'] ?? null);
            if (($contacts['version'] ?? null) !== 1 || ($contacts['status'] ?? null) !== 'collected' || $pageUrl === null) {
                $partial = true;

                continue;
            }
            $partial = $partial || ! empty($contacts['truncated']);
            $emails = $contacts['emails'] ?? [];
            if (! is_array($emails)) {
                $partial = true;
                $emails = [];
            }
            $partial = $partial || count($emails) > $maxEmails;
            foreach (array_slice($emails, 0, $maxEmails) as $candidate) {
                $email = is_array($candidate) ? ContactEvidence::email($candidate['value'] ?? null) : null;
                $url = is_array($candidate) ? ContactEvidence::url($candidate['source_url'] ?? null) : null;
                if ($email === null || $url !== $pageUrl) {
                    $partial = true;

                    continue;
                }
                $methods = is_array($candidate['source_methods'] ?? null) ? $candidate['source_methods'] : [];
                $methods = array_values(array_intersect(['mailto', 'html_text', 'organization_jsonld'], array_filter($methods, 'is_string')));
                $result['emails'][] = [
                    'email' => $email,
                    'url' => $url,
                    'methods' => $methods,
                    'original_hint' => is_string($candidate['purpose_hint'] ?? null) ? substr($candidate['purpose_hint'], 0, 64) : null,
                ];
            }

            foreach (['contact_page_urls' => 'Contact page', 'forms' => 'Contact form'] as $key => $label) {
                $items = is_array($contacts[$key] ?? null) ? $contacts[$key] : [];
                foreach (array_slice($items, 0, 50) as $item) {
                    $url = ContactEvidence::url($key === 'forms' ? (is_array($item) ? ($item['source_url'] ?? null) : null) : $item);
                    if ($url !== null && ContactEvidence::host(parse_url($url, PHP_URL_HOST)) === ContactEvidence::host(parse_url($pageUrl, PHP_URL_HOST))) {
                        $result['links'][$key.':'.$url] = ['url' => $url, 'label' => $label];
                    }
                }
            }
        }

        $result['links'] = array_values($result['links']);
        $result['status'] = ! $found ? 'unavailable' : ($partial ? 'partial' : 'collected');

        return $result;
    }
}
