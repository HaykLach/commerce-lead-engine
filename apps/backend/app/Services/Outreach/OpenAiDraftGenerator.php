<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Throwable;

class OpenAiDraftGenerator implements DraftGenerator
{
    public const ENDPOINT = 'https://api.openai.com/v1/responses';

    public function generate(array $prompt, string $model): array
    {
        if (! config('outreach.enabled') || blank(config('outreach.api_key')) || $model === '') {
            throw new DraftException('not_configured', 'Configure and enable the OpenAI drafting integration first.');
        }
        $stream = Utils::streamFor(fopen('php://temp/maxmemory:1048576', 'w+'));
        $size = 0;
        $sink = FnStream::decorate($stream, ['write' => function (string $chunk) use ($stream, &$size): int {
            $size += strlen($chunk);
            if ($size > 1_000_000) {
                throw new DraftException('response_too_large', 'The drafting response exceeded its size limit.');
            }

            return $stream->write($chunk);
        }]);
        try {
            $response = Http::withToken(config('outreach.api_key'))->acceptJson()->connectTimeout(10)->timeout(60)
                ->withOptions(['allow_redirects' => false, 'sink' => $sink])->post(self::ENDPOINT, [
                    'model' => $model, 'store' => false, 'instructions' => $prompt['instructions'],
                    'input' => [['role' => 'user', 'content' => json_encode($prompt['input'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]],
                    'text' => ['format' => $prompt['format']], 'max_output_tokens' => min(3000, max(500, (int) config('outreach.max_output_tokens'))),
                ]);
            if (strlen($response->body()) > 1_000_000) {
                throw new DraftException('response_too_large', 'The drafting response exceeded its size limit.');
            }
            if (! $response->successful()) {
                $status = $response->status();
                $quota = data_get($response->json(), 'error.code') === 'insufficient_quota';
                $after = $response->header('Retry-After');
                $seconds = ctype_digit($after) ? (int) $after : max(0, (strtotime($after) ?: time()) - time());
                throw new DraftException($quota ? 'insufficient_quota' : 'http_'.$status,
                    $quota ? 'The OpenAI project has insufficient API quota.' : 'OpenAI returned HTTP '.$status.'.',
                    ! $quota && ($status === 429 || $status >= 500), min(86400, max(60, $seconds)));
            }
            $data = $response->json();
            if (! is_array($data)) {
                throw new DraftException('invalid_response', 'The drafting provider returned invalid JSON.');
            }

            return $data;
        } catch (DraftException $exception) {
            throw $exception;
        } catch (Throwable) {
            // An ambiguous paid request must not be repeated automatically.
            throw new DraftException('connection_unknown', 'The OpenAI request did not complete locally. Check API usage before regenerating.');
        } finally {
            $sink->close();
        }
    }
}
