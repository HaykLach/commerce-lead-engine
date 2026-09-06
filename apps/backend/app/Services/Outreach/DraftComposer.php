<?php

declare(strict_types=1);

namespace App\Services\Outreach;

class DraftComposer
{
    public function compose(array $response, array $evidence, array $prompt): array
    {
        if (($response['status'] ?? null) !== 'completed') {
            throw new DraftException('incomplete', 'OpenAI did not complete the draft. Check the model and output-token setting.');
        }
        $texts = [];
        foreach ($response['output'] ?? [] as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? null) === 'refusal') {
                    throw new DraftException('refused', 'The model declined to generate this draft.');
                }
                if (($content['type'] ?? null) === 'output_text' && is_string($content['text'] ?? null)) {
                    $texts[] = $content['text'];
                }
            }
        }
        $draft = count($texts) === 1 ? json_decode($texts[0], true) : null;
        if (! is_array($draft) || count($draft) !== 3 || ! isset($draft['subject'], $draft['opening'], $draft['issue_ids'])
            || ! $this->plain($draft['subject'], 100) || ! $this->plain($draft['opening'], 200)
            || ! is_array($draft['issue_ids']) || ! array_is_list($draft['issue_ids']) || count($draft['issue_ids']) < 1 || count($draft['issue_ids']) > 2) {
            throw new DraftException('invalid_output', 'The generated draft did not match the required format.');
        }
        $freeText = str_replace($evidence['domain'], '', $draft['subject'].' '.$draft['opening']);
        if (preg_match('/https?:|www\.|@|\d|\b(speed|slow|fast|loading|performance|score|lighthouse|pagespeed|sales|revenue|conversion\w*|losing|lost|penalt\w*|rank\w*|missing|broken|poor|weak|vulnerab\w*|guarantee\w*|free|report|attachment)\b/i', $freeText)) {
            throw new DraftException('unsupported_claim', 'The generated subject or opening contains an unsupported claim or report detail.');
        }
        $facts = array_column($evidence['facts'], null, 'id');
        $selected = [];
        foreach ($draft['issue_ids'] as $id) {
            if (! is_string($id) || ! isset($facts[$id]) || isset($selected[$id])) {
                throw new DraftException('invalid_evidence', 'The model referenced missing or duplicate evidence.');
            }
            $selected[$id] = $facts[$id]['text'];
        }
        $body = implode("\n\n", [trim($draft['opening']), implode(' ', $selected), $prompt['positioning'], $prompt['cta'], $prompt['signature']]);

        return ['subject' => trim($draft['subject']), 'body' => $body, 'selected_issue_ids' => array_keys($selected)];
    }

    private function plain(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max
            && ! preg_match('/[\x00-\x1f\x7f<>]/', $value);
    }
}
