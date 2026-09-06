<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Models\Domain;
use App\Models\OutreachDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DraftRunner
{
    public function __construct(private readonly DraftGenerator $generator, private readonly DraftComposer $composer, private readonly DraftEvidenceBuilder $evidence, private readonly DraftBudget $budget) {}

    public function current(OutreachDraft $draft): bool
    {
        try {
            // MySQL JSON storage may reorder object keys.
            return $this->evidence->build($draft->domain) == $draft->evidence;
        } catch (DraftException) {
            return false;
        }
    }

    public function run(int $id): ?int
    {
        $candidate = OutreachDraft::query()->find($id);
        if ($candidate === null) {
            return null;
        }
        $token = (string) Str::uuid();
        $draft = DB::transaction(function () use ($candidate, $token): ?OutreachDraft {
            Domain::query()->lockForUpdate()->findOrFail($candidate->domain_id);
            $row = OutreachDraft::query()->lockForUpdate()->find($candidate->id);
            if ($row === null || $row->active_domain_id === null || $row->lease_until?->isFuture()) {
                return null;
            }
            if ($row->status === 'generating') {
                $row->update(['status' => 'failed', 'active_domain_id' => null, 'lease_token' => null, 'lease_until' => null, 'finished_at' => now(),
                    'error_code' => 'generation_unknown', 'error' => 'The previous worker stopped during a possibly billed request. Check API usage before regenerating.']);

                return null;
            }
            $row->update(['lease_token' => $token, 'lease_until' => now()->addSeconds(85)]);

            return $row;
        });
        if ($draft === null) {
            return null;
        }
        $attempted = false;
        try {
            if (! config('outreach.enabled') || blank(config('outreach.api_key'))) {
                throw new DraftException('not_configured', 'The OpenAI drafting integration is disabled or not configured.');
            }
            if (! $this->current($draft)) {
                throw new DraftException('source_changed', 'The selected contact or audit evidence changed. Generate a new draft.');
            }
            if ($draft->attempts >= 3) {
                throw new DraftException('attempt_limit', 'Draft generation exceeded its retry limit.');
            }
            $delay = $draft->next_attempt_at?->isFuture() ? $draft->next_attempt_at->timestamp - now()->timestamp : $this->budget->reserve();
            if ($delay > 0) {
                $this->persist($draft, $token, ['lease_token' => null, 'lease_until' => null, 'next_attempt_at' => now()->addSeconds($delay)]);

                return $delay;
            }
            if (! $this->persist($draft, $token, ['status' => 'generating', 'attempts' => $draft->attempts + 1, 'error' => null, 'error_code' => null], true)) {
                return null;
            }
            $draft->refresh();
            $attempted = true;
            $response = $this->generator->generate($draft->prompt, $draft->model);
            $usage = [];
            foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $key) {
                $value = $response['usage'][$key] ?? null;
                $usage[$key] = is_int($value) && $value >= 0 ? $value : null;
            }
            $this->persist($draft, $token, ['response_id' => is_string($response['id'] ?? null) ? mb_substr($response['id'], 0, 100) : null,
                'response_model' => is_string($response['model'] ?? null) ? mb_substr($response['model'], 0, 100) : null, 'usage' => $usage]);
            $content = $this->composer->compose($response, $draft->evidence, $draft->prompt);
            $this->persist($draft, $token, $content + ['generated_subject' => $content['subject'], 'generated_body' => $content['body'],
                'status' => 'draft', 'active_domain_id' => null, 'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => null, 'finished_at' => now()], true);

            return null;
        } catch (Throwable $exception) {
            $failure = $exception instanceof DraftException ? $exception : new DraftException('generation_failed', 'Draft generation could not finish. Check logs and API usage before regenerating.');
            $attempts = min(3, $draft->attempts + ($attempted ? 0 : 1));
            $retry = $failure->retryable && $attempts < 3;
            $delay = max($attempts === 1 ? 60 : 300, $failure->retryAfter);
            $this->persist($draft, $token, ['attempts' => $attempts, 'status' => $retry ? 'queued' : ($failure->reason === 'source_changed' ? 'blocked' : 'failed'),
                'active_domain_id' => $retry ? $draft->domain_id : null, 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => $retry ? now()->addSeconds($delay) : null, 'finished_at' => $retry ? null : now(),
                'error_code' => $failure->reason, 'error' => $failure->getMessage()]);

            return $retry ? $delay : null;
        }
    }

    private function persist(OutreachDraft $draft, string $token, array $values, bool $checkSource = false): bool
    {
        return DB::transaction(function () use ($draft, $token, $values, $checkSource): bool {
            Domain::query()->lockForUpdate()->findOrFail($draft->domain_id);
            $row = OutreachDraft::query()->lockForUpdate()->find($draft->id);
            if ($row === null || $row->active_domain_id === null || $row->lease_token !== $token) {
                return false;
            }
            if ($checkSource && ! $this->current($row)) {
                throw new DraftException('source_changed', 'The selected contact or audit evidence changed. Generate a new draft.');
            }

            return $row->update($values);
        });
    }
}
