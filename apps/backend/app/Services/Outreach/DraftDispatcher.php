<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Jobs\GenerateOutreachDraft;
use App\Models\Domain;
use App\Models\OutreachDraft;
use Illuminate\Support\Facades\DB;

class DraftDispatcher
{
    public function __construct(private readonly DraftEvidenceBuilder $evidence, private readonly DraftPrompt $prompts) {}

    public function assertConfigured(): void
    {
        if (! config('outreach.enabled') || blank(config('outreach.api_key')) || blank(config('outreach.model'))) {
            throw new DraftException('not_configured', 'Set the OpenAI API key and drafting model, then enable email drafting.');
        }
        $connection = config('outreach.connection');
        if (! in_array(config("queue.connections.{$connection}.driver"), ['database', 'redis'], true)
            || (int) config("queue.connections.{$connection}.retry_after") <= 85) {
            throw new DraftException('invalid_queue', 'Drafting requires a database or Redis queue with retry_after greater than 85 seconds.');
        }
    }

    public function request(Domain $domain, bool $regenerate = false): OutreachDraft
    {
        $this->assertConfigured();

        return DB::transaction(function () use ($domain, $regenerate): OutreachDraft {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $active = OutreachDraft::query()->where('active_domain_id', $domain->id)->first();
            if ($active !== null) {
                return $active;
            }
            $evidence = $this->evidence->build($domain);
            $prompt = $this->prompts->build($evidence);
            $hash = hash('sha256', json_encode([$evidence, $prompt, config('outreach.model'), config('outreach.prompt_version')], JSON_THROW_ON_ERROR));
            if (! $regenerate) {
                $existing = $domain->outreachDrafts()->where('input_hash', $hash)->latest('id')->first();
                if ($existing !== null) {
                    return $existing;
                }
            }
            $blocked = $evidence['facts'] === [];
            $draft = $domain->outreachDrafts()->create(['website_audit_id' => $evidence['audit_id'], 'domain_contact_id' => $evidence['contact_id'],
                'recipient_email' => $evidence['recipient_email'], 'active_domain_id' => $blocked ? null : $domain->id,
                'status' => $blocked ? 'blocked' : 'queued', 'evidence' => $evidence, 'prompt' => $prompt, 'input_hash' => $hash,
                'model' => config('outreach.model'), 'prompt_version' => config('outreach.prompt_version'),
                'next_attempt_at' => $blocked ? null : now(), 'finished_at' => $blocked ? now() : null,
                'error_code' => $blocked ? 'no_findings' : null, 'error' => $blocked ? 'No supported website weaknesses are available for an outreach draft.' : null]);
            if (! $blocked) {
                $this->enqueue($draft->id);
            }

            return $draft;
        }, 3);
    }

    public function enqueue(int $id): void
    {
        GenerateOutreachDraft::dispatch($id)->onConnection(config('outreach.connection'))->onQueue(config('outreach.queue'))->afterCommit();
    }

    public function recover(int $limit): int
    {
        $rows = OutreachDraft::query()->whereNotNull('active_domain_id')->where('updated_at', '<', now()->subMinutes(5))
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit($limit)->get();
        $count = 0;
        foreach ($rows as $candidate) {
            $count += DB::transaction(function () use ($candidate): int {
                Domain::query()->lockForUpdate()->findOrFail($candidate->domain_id);
                $row = OutreachDraft::query()->lockForUpdate()->find($candidate->id);
                if ($row === null || $row->active_domain_id === null || $row->lease_until?->isFuture() || $row->next_attempt_at?->isFuture()
                    || $row->updated_at->gt(now()->subMinutes(5))) {
                    return 0;
                }
                if ($row->status === 'generating') {
                    $row->update(['status' => 'failed', 'active_domain_id' => null, 'lease_token' => null, 'lease_until' => null, 'finished_at' => now(),
                        'error_code' => 'generation_unknown', 'error' => 'The worker stopped during a possibly billed request. Check API usage before regenerating.']);
                } else {
                    $row->touch();
                    $this->enqueue($row->id);
                }

                return 1;
            });
        }

        return $count;
    }
}
