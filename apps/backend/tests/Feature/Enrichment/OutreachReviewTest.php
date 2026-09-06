<?php

declare(strict_types=1);

namespace Tests\Feature\Enrichment;

use App\Filament\Pages\OutreachReports;
use App\Jobs\SendApprovedOutreach;
use App\Jobs\SendDailyOutreachReport;
use App\Livewire\DomainOutreachDrafts;
use App\Models\DailyOutreachReport;
use App\Models\Domain;
use App\Models\OutreachDraft;
use App\Models\OutreachMessage;
use App\Models\User;
use App\Services\Contacts\ContactSelectionService;
use App\Services\Outreach\DailyReports;
use App\Services\Outreach\DraftEvidenceBuilder;
use App\Services\Outreach\MessageApproval;
use App\Services\Outreach\MessageDelivery;
use App\Services\Outreach\OutreachTransport;
use App\Services\Outreach\SmtpOutreachTransport;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Tests\TestCase;

class OutreachReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 6)->setTime(12, 0));
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'app.url' => 'https://leads.test',
            'mail.from.address' => 'ruben@ffp.test', 'outreach_delivery.enabled' => true]);
        Queue::fake();
        Http::preventStrayRequests();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    private function draft(): OutreachDraft
    {
        $host = 'shop'.(Domain::count() + 1).'.test';
        $domain = Domain::factory()->create(['domain' => $host, 'normalized_domain' => $host]);
        $contacts = app(ContactSelectionService::class);
        $contact = $contacts->addManual($domain, 'info@'.$host);
        $contacts->select($domain, $contact->id);
        $audit = $domain->websiteAudits()->create(['status' => 'completed', 'finished_at' => now(), 'expires_at' => now()->addDays(7)]);
        $url = 'https://'.$host.'/';
        $audit->pages()->create(['kind' => 'homepage', 'status' => 'completed', 'url' => $url, 'final_url' => $url,
            'url_hash' => hash('sha256', $url), 'fetched_at' => now(), 'evidence' => ['metadata' => ['description' => '']]]);
        $evidence = app(DraftEvidenceBuilder::class)->build($domain->fresh());

        return $domain->outreachDrafts()->create(['website_audit_id' => $audit->id, 'domain_contact_id' => $contact->id,
            'recipient_email' => $contact->email, 'status' => 'draft', 'input_hash' => hash('sha256', json_encode($evidence)),
            'evidence' => $evidence, 'prompt' => ['cta' => config('outreach.cta'), 'signature' => config('outreach.signature')],
            'prompt_version' => 'test', 'model' => 'test', 'subject' => 'Storefront improvements',
            'body' => "Hello\n".config('outreach.cta')."\n".config('outreach.signature'), 'revision' => 0]);
    }

    private function approve(?OutreachDraft $draft = null): OutreachMessage
    {
        $draft ??= $this->draft();

        return app(MessageApproval::class)->approve($draft->domain_id, $draft->id, $draft->revision, '2026-09-06T17:00');
    }

    public function test_approval_freezes_content_converts_armenia_time_and_never_sends_early(): void
    {
        $draft = $this->draft();
        $message = $this->approve($draft);
        $this->assertSame('2026-09-06 13:00:00', $message->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame($draft->body, $message->body);
        $this->assertNotNull($message->approved_by);
        $this->mock(OutreachTransport::class)->shouldReceive('send')->once();
        app(MessageDelivery::class)->run($message->id);
        $this->assertSame('scheduled', $message->fresh()->status);
        $this->assertSame(0, app(MessageDelivery::class)->dispatchDue());
        $this->travelTo(now()->setTime(13, 0));
        $this->assertSame(1, app(MessageDelivery::class)->dispatchDue());
        Queue::assertPushed(SendApprovedOutreach::class, fn ($job) => $job->connection === 'database' && $job->queue === 'outreach-delivery');
        app(MessageDelivery::class)->run($message->id);
        app(MessageDelivery::class)->run($message->id);
        $this->assertSame('accepted', $message->fresh()->status);
        $this->assertNotNull($message->fresh()->accepted_at);
    }

    public function test_disabled_delivery_does_not_consume_message_or_call_transport(): void
    {
        $message = $this->approve();
        config(['outreach_delivery.enabled' => false]);
        $this->travelTo(now()->setTime(13, 0));
        $this->mock(OutreachTransport::class)->shouldNotReceive('send');
        app(MessageDelivery::class)->run($message->id);
        $this->assertSame(0, app(MessageDelivery::class)->dispatchDue());
        $this->assertSame('scheduled', $message->fresh()->status);
        $this->assertNull($message->fresh()->send_started_at);
    }

    public function test_lost_enqueue_is_recovered_and_stale_sending_is_not_retried(): void
    {
        $message = $this->approve();
        $this->travelTo(now()->setTime(13, 0));
        $message->update(['dispatched_at' => now()->subMinutes(6)]);
        $this->assertSame(1, app(MessageDelivery::class)->dispatchDue());
        $message->update(['status' => 'sending', 'send_started_at' => now()->subMinutes(6)]);
        $this->assertSame(0, app(MessageDelivery::class)->dispatchDue());
        $this->assertSame('unknown', $message->fresh()->status);
    }

    public function test_contact_exclusion_blocks_an_already_approved_email(): void
    {
        $message = $this->approve();
        $draft = $message->draft;
        app(ContactSelectionService::class)->exclude($draft->domain, $draft->domain_contact_id, true);
        $this->travelTo(now()->setTime(13, 0));
        $this->mock(OutreachTransport::class)->shouldNotReceive('send');
        app(MessageDelivery::class)->run($message->id);
        $this->assertSame('blocked', $message->fresh()->status);
    }

    public function test_changed_snapshot_or_expired_evidence_blocks_sending(): void
    {
        $first = $this->approve();
        $second = $this->approve();
        $first->draft->update(['body' => 'Changed without approval']);
        $second->draft->audit->update(['expires_at' => now()]);
        $this->travelTo(now()->setTime(13, 0));
        $this->mock(OutreachTransport::class)->shouldNotReceive('send');
        app(MessageDelivery::class)->run($first->id);
        app(MessageDelivery::class)->run($second->id);
        $this->assertSame('blocked', $first->fresh()->status);
        $this->assertSame('blocked', $second->fresh()->status);
    }

    public function test_smtp_timeout_remains_unknown_and_never_blindly_retries(): void
    {
        $message = $this->approve();
        $this->travelTo(now()->setTime(13, 0));
        $this->mock(OutreachTransport::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp-password-secret'));
        app(MessageDelivery::class)->run($message->id);
        app(MessageDelivery::class)->run($message->id);
        $this->assertSame('unknown', $message->fresh()->status);
        $this->assertStringNotContainsString('secret', $message->fresh()->toJson());
        $this->assertSame(0, app(MessageDelivery::class)->dispatchDue());
    }

    public function test_global_spacing_and_rolling_day_limit_defer_without_sending(): void
    {
        config(['outreach_delivery.daily_limit' => 2]);
        $one = $this->approve();
        $two = $this->approve();
        $three = $this->approve();
        $this->travelTo(now()->setTime(13, 0));
        $this->mock(OutreachTransport::class)->shouldReceive('send')->twice();
        $runner = app(MessageDelivery::class);
        $runner->run($one->id);
        $runner->run($two->id);
        $this->assertSame('scheduled', $two->fresh()->status);
        $this->assertSame('13:01', $two->fresh()->next_attempt_at->format('H:i'));
        $this->travel(60)->seconds();
        $runner->run($two->id);
        $runner->run($three->id);
        $this->assertSame('2026-09-07 13:00:01', $three->fresh()->next_attempt_at->format('Y-m-d H:i:s'));
        $this->assertNull($three->fresh()->send_started_at);
    }

    public function test_cancel_allows_edits_and_new_approval_and_old_job_cannot_send(): void
    {
        $draft = $this->draft();
        $message = $this->approve($draft);
        app(MessageApproval::class)->cancel($draft->domain_id, $message->id);
        $draft->refresh();
        $this->assertSame('draft', $draft->status);
        $draft->update(['subject' => 'Reviewed subject', 'revision' => 1]);
        $new = $this->approve($draft);
        $this->assertSame('Reviewed subject', $new->subject);
        $this->assertSame('Storefront improvements', $message->fresh()->subject);
        $this->travelTo(now()->setTime(13, 0));
        $this->mock(OutreachTransport::class)->shouldReceive('send')->once()->withArgs(fn ($m) => $m->id === $new->id);
        app(MessageDelivery::class)->run($message->id);
        app(MessageDelivery::class)->run($new->id);
    }

    public function test_rescheduling_prevents_old_due_job_from_sending(): void
    {
        $message = $this->approve();
        app(MessageApproval::class)->reschedule($message->domain_id, $message->id, '2026-09-06T19:30');
        $this->travelTo(now()->setTime(13, 0));
        $this->mock(OutreachTransport::class)->shouldNotReceive('send');
        app(MessageDelivery::class)->run($message->id);
        $this->assertSame('15:30', $message->fresh()->scheduled_at->format('H:i'));
        $this->assertSame('scheduled', $message->fresh()->status);
    }

    public function test_repeated_approval_and_second_draft_for_same_domain_are_blocked(): void
    {
        $draft = $this->draft();
        $this->approve($draft);
        $draft->update(['status' => 'draft']);
        $this->expectException(ValidationException::class);
        $this->approve($draft);
    }

    public function test_sync_queue_and_past_or_expired_schedule_are_rejected(): void
    {
        $draft = $this->draft();
        foreach (['2026-09-06T15:00', '2026-09-16T17:00'] as $time) {
            try {
                app(MessageApproval::class)->approve($draft->domain_id, $draft->id, 0, $time);
                $this->fail('Invalid schedule accepted');
            } catch (ValidationException) {
                $this->assertSame(0, OutreachMessage::count());
            }
        }
        config(['outreach_delivery.connection' => 'sync']);
        $this->expectException(ValidationException::class);
        $this->approve($draft);
    }

    public function test_review_page_editor_requires_saved_changes_and_honors_authorization(): void
    {
        $draft = $this->draft();
        Livewire::test(OutreachReports::class)->assertSee('Storefront improvements')->call('selectDraft', $draft->id)->assertSee('Review email');
        $editor = Livewire::test(DomainOutreachDrafts::class, ['domainId' => $draft->domain_id, 'initialDraftId' => $draft->id]);
        $editor->set('subject', 'Updated subject')->set('scheduledFor', '2026-09-06T17:00')
            ->call('approveAndSchedule')->assertHasErrors('draft')->call('saveDraft')->assertHasNoErrors()
            ->call('approveAndSchedule')->assertHasNoErrors()->assertSee('Scheduled for');
        $this->assertSame('Updated subject', OutreachMessage::sole()->subject);
        $editor->set('subject', 'After approval')->call('saveDraft')->assertHasErrors('draft');
        Gate::policy(Domain::class, ReviewReadOnlyPolicy::class);
        $editor->call('cancelMessage', OutreachMessage::sole()->id)->assertForbidden();
        $this->assertSame('scheduled', OutreachMessage::sole()->status);
    }

    public function test_report_day_uses_armenia_midnight_and_notification_has_no_email_content(): void
    {
        $reports = app(DailyReports::class);
        [$start, $end] = $reports->bounds('2026-09-06');
        $this->assertSame('2026-09-05 20:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-06 20:00:00', $end->format('Y-m-d H:i:s'));
        $draft = $this->draft();
        $draft->forceFill(['created_at' => $start->subSecond()])->save();
        $this->assertSame(0, $reports->drafts('2026-09-06')->count());
        $draft->forceFill(['created_at' => $start])->save();
        $report = $reports->prepare('2026-09-06');
        $this->assertSame(1, $report->summary['drafts']['draft']);
        $this->assertStringContainsString('Ready for review: 1', $reports->text($report));
        $this->assertStringContainsString('https://leads.test/admin/outreach-reports?date=2026-09-06', $reports->text($report));
        $this->assertStringNotContainsString($draft->recipient_email, $reports->text($report));
        $this->assertStringNotContainsString($draft->subject, $reports->text($report));
    }

    public function test_telegram_summary_is_unique_per_day_and_jobs_are_idempotent(): void
    {
        config(['outreach_delivery.telegram_enabled' => true, 'outreach_delivery.telegram_token' => 'test-secret', 'outreach_delivery.telegram_chat_id' => '-123']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
        $reports = app(DailyReports::class);
        $report = $reports->prepare('2026-09-06');
        $reports->prepare('2026-09-06');
        Queue::assertPushed(SendDailyOutreachReport::class, 1);
        (new SendDailyOutreachReport($report->id))->handle($reports);
        (new SendDailyOutreachReport($report->id))->handle($reports);
        $this->assertSame('sent', $report->fresh()->notification_status);
        $this->assertSame(1, DailyOutreachReport::count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['chat_id'] === '-123' && $request['link_preview_options']['is_disabled'] === true);
    }

    public function test_daily_scheduler_opens_at_22_armenia_and_allows_same_evening_recovery(): void
    {
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => $event->description === 'daily-outreach-report');
        $this->assertNotNull($event);
        $this->assertSame('Asia/Yerevan', $event->timezone);
        $this->travelTo(now()->setTime(17, 59));
        $this->assertFalse($event->filtersPass($this->app));
        $this->travelTo(now()->setTime(18, 0));
        $this->assertTrue($event->filtersPass($this->app));
        $this->travelTo(now()->setTime(18, 10));
        $this->assertTrue($event->filtersPass($this->app));
        $this->travelTo(now()->setTime(20, 0));
        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_telegram_disabled_still_builds_an_honest_report_of_partial_and_pending_work(): void
    {
        $draft = $this->draft();
        $draft->update(['status' => 'queued']);
        $draft->audit->update(['status' => 'partial']);
        config(['outreach_delivery.telegram_enabled' => false, 'outreach.enabled' => false]);
        $reports = app(DailyReports::class);
        $report = $reports->prepare('2026-09-06');
        (new SendDailyOutreachReport($report->id))->handle($reports);
        Queue::assertNotPushed(SendDailyOutreachReport::class);
        Http::assertNothingSent();
        $this->assertStringContainsString('Ready for review: 0', $reports->text($report));
        $this->assertStringContainsString('Unfinished jobs (including backlog): 1', $reports->text($report));
        $this->assertStringContainsString('Partial audits: 1', $reports->text($report));
        $this->assertStringContainsString('not configured or enabled', $reports->text($report));
    }

    public function test_report_and_approval_require_authenticated_authorized_users(): void
    {
        $draft = $this->draft();
        config(['app.env' => 'local']); // Existing panel permits this user model only in local environments.
        $this->get('/admin/outreach-reports')->assertOk();
        auth()->logout();
        $this->get('/admin/outreach-reports')->assertRedirect('/admin/login');
        Livewire::test(DomainOutreachDrafts::class, ['domainId' => $draft->domain_id])->assertForbidden();
    }

    public function test_smtp_adapter_hands_off_only_frozen_content_to_explicit_recipient(): void
    {
        $message = $this->approve();
        $transport = \Mockery::mock(TransportInterface::class);
        $transport->shouldReceive('send')->once()->withArgs(function ($email, $envelope) use ($message) {
            $this->assertSame($message->subject, $email->getSubject());
            $this->assertSame($message->body, $email->getTextBody());
            $this->assertSame($message->message_id, $email->getHeaders()->get('Message-ID')->getId());
            $this->assertSame([$message->recipient_email], array_map(fn ($address) => $address->getAddress(), $envelope->getRecipients()));

            return true;
        })->andReturnUsing(fn ($email, $envelope) => new SentMessage($email, $envelope));
        $mailer = \Mockery::mock(Mailer::class);
        $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn($transport);
        Mail::shouldReceive('mailer')->with('outreach')->once()->andReturn($mailer);
        app(SmtpOutreachTransport::class)->send($message);
    }

    public function test_telegram_timeout_does_not_leak_token_or_repeat_notification(): void
    {
        config(['outreach_delivery.telegram_enabled' => true, 'outreach_delivery.telegram_token' => 'test-secret', 'outreach_delivery.telegram_chat_id' => '-123']);
        Http::fake(fn () => throw new ConnectionException('https://api.telegram.org/bottest-secret/sendMessage'));
        $reports = app(DailyReports::class);
        $report = $reports->prepare('2026-09-06');
        (new SendDailyOutreachReport($report->id))->handle($reports);
        $this->assertSame('unknown', $report->fresh()->notification_status);
        $this->assertStringNotContainsString('test-secret', $report->fresh()->toJson());
        $reports->prepare('2026-09-06');
        Queue::assertPushed(SendDailyOutreachReport::class, 1);
    }
}

class ReviewReadOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Domain $domain): bool
    {
        return true;
    }

    public function update(User $user, Domain $domain): bool
    {
        return false;
    }
}
