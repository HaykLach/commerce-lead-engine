<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Models\OutreachMessage;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageApproval
{
    public function approve(int $domainId, int $draftId, int $revision, string $localTime): OutreachMessage
    {
        DeliveryQueue::connection();
        $scheduled = $this->time($localTime);

        return DB::transaction(function () use ($domainId, $draftId, $revision, $scheduled): OutreachMessage {
            $domain = $this->domain($domainId);
            $draft = $domain->outreachDrafts()->lockForUpdate()->findOrFail($draftId);
            if ($draft->status !== 'draft' || $draft->revision !== $revision || ! app(DraftRunner::class)->current($draft)) {
                $this->invalid('Reload the draft. Its content, contact or source evidence has changed.');
            }
            if (OutreachMessage::where('active_domain_id', $domainId)->exists()) {
                $this->invalid('This domain already has scheduled or attempted outreach. Cancel a pending message before approving another.');
            }
            if ($scheduled->gte(CarbonImmutable::parse($draft->evidence['expires_at']))) {
                $this->invalid('Choose a send time before the source evidence expires, or refresh the analysis first.');
            }
            $from = (string) config('mail.from.address');
            if (! filter_var($from, FILTER_VALIDATE_EMAIL) || str_ends_with($from, '@example.com')) {
                $this->invalid('Configure MAIL_FROM_ADDRESS with your sending mailbox before approving.');
            }
            $message = OutreachMessage::create([
                'domain_id' => $domainId, 'outreach_draft_id' => $draftId, 'active_domain_id' => $domainId,
                'approved_by' => Filament::auth()->id(), 'draft_revision' => $revision,
                'recipient_email' => $draft->recipient_email, 'subject' => $draft->subject, 'body' => $draft->body, 'body_html' => $draft->body_html,
                'from_email' => $from, 'from_name' => (string) config('mail.from.name'),
                'message_id' => Str::uuid().'@'.Str::after($from, '@'),
                'status' => 'scheduled', 'approved_at' => now(), 'scheduled_at' => $scheduled, 'next_attempt_at' => $scheduled,
            ]);
            $draft->update(['status' => 'scheduled']);

            // The durable row is the outbox. The minute dispatcher recovers queue outages.
            return $message;
        });
    }

    public function cancel(int $domainId, int $messageId): void
    {
        DB::transaction(function () use ($domainId, $messageId): void {
            $domain = $this->domain($domainId);
            $message = OutreachMessage::where('domain_id', $domainId)->lockForUpdate()->findOrFail($messageId);
            if (! in_array($message->status, ['scheduled', 'blocked'], true)) {
                $this->invalid('Only a pending or blocked message can be cancelled.');
            }
            $message->update(['status' => 'cancelled', 'active_domain_id' => null]);
            $domain->outreachDrafts()->whereKey($message->outreach_draft_id)->where('status', 'scheduled')->update(['status' => 'draft']);
        });
    }

    public function reschedule(int $domainId, int $messageId, string $localTime): void
    {
        $time = $this->time($localTime);
        DB::transaction(function () use ($domainId, $messageId, $time): void {
            $this->domain($domainId);
            $message = OutreachMessage::where('domain_id', $domainId)->lockForUpdate()->findOrFail($messageId);
            if ($message->status !== 'scheduled' || ! app(DraftRunner::class)->current($message->draft)
                || $time->gte(CarbonImmutable::parse($message->draft->evidence['expires_at']))) {
                $this->invalid('This message cannot be rescheduled. Cancel it and review a fresh draft.');
            }
            $message->update(['scheduled_at' => $time, 'next_attempt_at' => $time, 'dispatched_at' => null, 'error' => null]);
        });
    }

    private function time(string $value): CarbonImmutable
    {
        validator(['scheduledFor' => $value], ['scheduledFor' => 'required|date_format:Y-m-d\TH:i'])->validate();
        $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, 'Asia/Yerevan')->utc();
        if ($time->lte(now()) || $time->gt(now()->addDays(30))) {
            throw ValidationException::withMessages(['scheduledFor' => 'Choose a future Armenia time within the next 30 days.']);
        }

        return $time;
    }

    private function domain(int $id): Domain
    {
        abort_unless(Filament::auth()->check(), 403);
        $domain = Domain::lockForUpdate()->findOrFail($id);
        abort_unless(DomainResource::canView($domain) && DomainResource::canEdit($domain), 403);

        return $domain;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['draft' => $message]);
    }
}
