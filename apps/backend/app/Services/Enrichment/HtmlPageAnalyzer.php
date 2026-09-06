<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Services\Contacts\ContactEvidence;
use App\Services\Contacts\ContactPurposeClassifier;
use DOMDocument;
use DOMElement;
use DOMXPath;

class HtmlPageAnalyzer
{
    private const EMAIL = <<<'REGEX'
~(?<![\w!#$%&'*+/=?^_`{|}\~.\-])[A-Z0-9!#$%&'*+/=?^_`{|}\~.\-]{1,64}@(?:[A-Z0-9](?:[A-Z0-9\-]{0,61}[A-Z0-9])?\.){1,125}[A-Z]{2,63}(?![\w@\-]|\.[A-Z0-9])~i
REGEX;

    public function __construct(private readonly ContactPurposeClassifier $purposes) {}

    public function analyze(string $html, string $url): array
    {
        if (strlen($html) > min(2_000_000, (int) config('enrichment.max_html_bytes'))) {
            throw new FetchException('The HTML exceeds the analysis size limit.');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.mb_convert_encoding($html, 'UTF-8', 'UTF-8'), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new FetchException('Unable to parse the page HTML.');
        }
        $xpath = new DOMXPath($document);
        $text = fn (string $query, int $limit = 300): string => mb_substr(trim((string) $xpath->evaluate("string({$query})")), 0, $limit);
        $canonical = $text('//link[@rel="canonical"]/@href', 2048);
        $metadata = [
            'title' => $text('//title'),
            'description' => $text('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content', 1000),
            'language' => $text('//html/@lang', 16),
            'canonical_url' => $canonical === '' ? null : AuditUrl::normalize($canonical, $url),
            'script_count' => $xpath->query('//script')->length,
            'images_total' => $xpath->query('//img')->length,
            'images_without_alt_attribute' => $xpath->query('//img[not(@alt)]')->length,
            'has_viewport_meta' => $xpath->query('//meta[@name="viewport"]')->length > 0,
        ];
        $contacts = ['version' => 1, 'status' => 'collected', 'emails' => [], 'forms' => [], 'contact_page_urls' => [], 'truncated' => false];
        $emails = [];
        $add = function (string $value, string $method) use (&$emails, &$contacts, $url): void {
            $email = ContactEvidence::email($value);
            if ($email === null || ! preg_match('~@(.+)~', $email, $parts)
                || in_array($parts[1], ['example.com', 'example.org', 'example.net'], true)
                || preg_match('/\.(png|jpe?g|gif|svg|webp|css|js|woff2?)$/i', $email)) {
                return;
            }
            if (! isset($emails[$email])) {
                if (count($emails) >= 50) {
                    $contacts['truncated'] = true;

                    return;
                }
                $emails[$email] = ['value' => $email, 'source_url' => $url, 'source_methods' => [], 'purpose_hint' => $this->purposes->classify($email), 'status' => 'candidate'];
            }
            $emails[$email]['source_methods'] = array_values(array_unique([...$emails[$email]['source_methods'], $method]));
        };

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $data = json_decode($node->textContent, true, 64);
            $pending = [$data];
            while ($pending !== []) {
                $record = array_pop($pending);
                if (! is_array($record)) {
                    continue;
                }
                $types = array_filter((array) ($record['@type'] ?? []), 'is_string');
                if (array_intersect(array_map('strtolower', $types), ['organization', 'corporation', 'localbusiness', 'store', 'onlinestore'])) {
                    $points = $record['contactPoint'] ?? [];
                    $points = is_array($points) ? (array_is_list($points) ? $points : [$points]) : [];
                    foreach ([$record, ...$points] as $point) {
                        if (is_array($point) && is_string($point['email'] ?? null)) {
                            $add(preg_replace('/^mailto:/i', '', $point['email']), 'organization_jsonld');
                        }
                    }
                    if (! isset($metadata['business_name']) && is_string($record['name'] ?? null)) {
                        $metadata['business_name'] = mb_substr($record['name'], 0, 255);
                    }
                }
                foreach ($record as $child) {
                    if (is_array($child)) {
                        $pending[] = $child;
                    }
                }
            }
        }
        foreach ($xpath->query('//script|//style|//head|//noscript|//template|//*[@hidden]|//*[@aria-hidden="true"]') as $node) {
            $node->parentNode?->removeChild($node);
        }
        $headings = [];
        foreach ($xpath->query('//h1') as $node) {
            if (count($headings) >= 5) {
                break;
            }
            $headings[] = mb_substr(trim($node->textContent), 0, 300);
        }
        $metadata['h1'] = $headings;
        $textNodes = [];
        foreach ($xpath->query('//text()[normalize-space()]') as $node) {
            $textNodes[] = $node->textContent;
        }
        $visibleText = preg_replace('/\s+/u', ' ', implode(' ', $textNodes));
        $metadata['text_excerpt'] = mb_substr(trim($visibleText), 0, 4000);
        preg_match_all(self::EMAIL, $visibleText, $matches);
        foreach ($matches[0] as $email) {
            $add($email, 'html_text');
        }
        $links = [];
        foreach ($xpath->query('//a[@href]') as $node) {
            $href = trim($node->getAttribute('href'));
            if (str_starts_with(strtolower($href), 'mailto:')) {
                foreach (preg_split('/[,;]/', rawurldecode(explode('?', substr($href, 7), 2)[0])) as $email) {
                    $add($email, 'mailto');
                }

                continue;
            }
            $link = AuditUrl::normalize($href, $url);
            if ($link === null || ! AuditUrl::sameSite($link, parse_url($url, PHP_URL_HOST))
                || preg_match('/cart|checkout|logout|add.to|delete|remove/i', rawurldecode($link))) {
                continue;
            }
            foreach (config('enrichment.page_patterns') as $kind => $pattern) {
                if (preg_match($pattern, rawurldecode(parse_url($link, PHP_URL_PATH)).' '.$node->textContent)) {
                    if (count($links) < 100) {
                        $links[$link] = ['url' => $link, 'kind' => $kind];
                    }
                    if (in_array($kind, ['contact', 'about', 'impressum'], true)) {
                        if (count($contacts['contact_page_urls']) < 50) {
                            $contacts['contact_page_urls'][$link] = $link;
                        } else {
                            $contacts['truncated'] = true;
                        }
                    }
                    break;
                }
            }
        }
        foreach ($xpath->query('//form') as $index => $form) {
            $fields = $xpath->query('.//input|.//textarea|.//select', $form);
            $names = [];
            $emailField = false;
            foreach ($fields as $field) {
                if (! $field instanceof DOMElement || in_array(strtolower($field->getAttribute('type')), ['hidden', 'password'], true)) {
                    continue;
                }
                $name = mb_substr($field->getAttribute('name'), 0, 100);
                $emailField = $emailField || strtolower($field->getAttribute('type')) === 'email' || str_contains(strtolower($name), 'email');
                if ($name !== '') {
                    $names[] = $name;
                }
            }
            $context = $form->getAttribute('id').' '.$form->getAttribute('class').' '.$form->getAttribute('action').' '.implode(' ', $names);
            if (! $emailField || $xpath->query('.//textarea', $form)->length === 0 || preg_match('/newsletter|subscribe|login|sign.?in|password|checkout|review|rating|comment/i', $context)) {
                continue;
            }
            if (count($contacts['forms']) >= 50) {
                $contacts['truncated'] = true;
                break;
            }
            $contacts['forms'][] = ['source_url' => $url, 'form_index' => $index, 'field_names' => array_slice(array_unique($names), 0, 50), 'status' => 'candidate'];
        }
        $contacts['emails'] = array_values($emails);
        $contacts['contact_page_urls'] = array_values($contacts['contact_page_urls']);

        return ['metadata' => $metadata, 'contacts' => $contacts, 'links' => array_values($links)];
    }
}
