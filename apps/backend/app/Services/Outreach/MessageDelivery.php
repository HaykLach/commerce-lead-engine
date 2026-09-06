<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Jobs\SendApprovedOutreach;
use App\Models\Domain;
use App\Models\OutreachMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class MessageDelivery
{
    public function dispatchDue(): int
    {
        $connection = DeliveryQueue::connection();
        OutreachMessage::where('status', 'sending')->where('send_started_at', '<', now()->subMinutes(5))
            ->update(['status' => 'unknown', 'error' => 'Worker stopped during SMTP handoff. Check the mailbox before any further action.']);
        if (! config('outreach_delivery.enabled')) {
            return 0;
        }
        $ids = OutreachMessage::where('status', 'scheduled')->where('next_attempt_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(5)))
            ->orderBy('next_attempt_at')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            // Record before enqueue; a lost enqueue becomes eligible again after five minutes.
            OutreachMessage::whereKey($id)->update(['dispatched_at' => now()]);
            SendApprovedOutreach::dispatch($id)->onConnection($connection)->onQueue(config('outreach_delivery.queue'));
        }

        return $ids->count();
    }

    public function run(int $id): void
    {
        if (! config('outreach_delivery.enabled')) {
            return;
        }
        $message = DB::transaction(function () use ($id): ?OutreachMessage {
            // Global reservation serializes limits across workers; never hold locks over network I/O.
            $gate = DB::table('outreach_delivery_locks')->where('id', 1)->lockForUpdate()->first();
            $candidate = OutreachMessage::find($id);
            if ($candidate === null) {
                return null;
            }
            Domain::lockForUpdate()->findOrFail($candidate->domain_id);
            $message = OutreachMessage::lockForUpdate()->findOrFail($id);
            if ($message->status !== 'scheduled' || $message->scheduled_at->isFuture() || $message->next_attempt_at->isFuture()) {
                return null;
            }
            $draft = $message->draft;
            if ($message->approved_at === null || $draft->status !== 'scheduled' || $draft->revision !== $message->draft_revision
                || $draft->recipient_email !== $message->recipient_email || $draft->subject !== $message->subject || $draft->body !== $message->body
                || ! app(DraftRunner::class)->current($draft)) {
                $message->update(['status' => 'blocked', 'error' => 'Contact, content or source evidence changed. Cancel and review a fresh draft.']);

                return null;
            }
            $recent = OutreachMessage::where('send_started_at', '>', now()->subDay());
            $next = now();
            if ($gate->last_attempt_at !== null) {
                $next = max($next, Carbon::parse($gate->last_attempt_at)->addSeconds(max(60, (int) config('outreach_delivery.spacing_seconds'))));
            }
            if ((clone $recent)->count() >= max(1, min(100, (int) config('outreach_delivery.daily_limit')))) {
                $next = max($next, Carbon::parse($recent->min('send_started_at'))->addDay()->addSecond());
            }
            if ($next->isFuture()) {
                $message->update(['next_attempt_at' => $next, 'dispatched_at' => null, 'error' => 'Waiting for the sending limit.']);

                return null;
            }
            DB::table('outreach_delivery_locks')->where('id', 1)->update(['last_attempt_at' => now()]);
            $message->update(['status' => 'sending', 'send_started_at' => now(), 'error' => null]);

            return $message;
        });
        if ($message === null) {
            return;
        }
        try {
            app(OutreachTransport::class)->send($message);
            $message->update(['status' => 'accepted', 'accepted_at' => now()]);
        } catch (Throwable) {
            // SMTP cannot guarantee idempotency. A timeout may occur after acceptance.
            $message->update(['status' => 'unknown', 'error' => 'SMTP acceptance is uncertain. Check the mailbox; automatic resend is disabled.']);
        }
    }
}
