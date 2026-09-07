<?php

declare(strict_types=1);

namespace Tests\Feature\Enrichment;

use App\Jobs\RunPageSpeedMeasurement;
use App\Livewire\DomainPageSpeed;
use App\Models\Domain;
use App\Models\PageSpeedMeasurement;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\Enrichment\FetchException;
use App\Services\Enrichment\PublicDnsResolver;
use App\Services\PageSpeed\PageSpeedBudget;
use App\Services\PageSpeed\PageSpeedClient;
use App\Services\PageSpeed\PageSpeedDispatcher;
use App\Services\PageSpeed\PageSpeedException;
use App\Services\PageSpeed\PageSpeedResultParser;
use App\Services\PageSpeed\PageSpeedRunner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PageSpeedIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['pagespeed.enabled' => true, 'pagespeed.cache_store' => 'array', 'pagespeed.requests_per_minute' => 100, 'pagespeed.requests_per_day' => 1000]);
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(PublicDnsResolver::class)->shouldReceive('resolve')->with('shop.test')->andReturn('8.8.8.8');
    }

    private function audit(array $kinds = ['homepage', 'category', 'contact', 'product']): WebsiteAudit
    {
        $domain = Domain::factory()->create(['domain' => 'shop.test', 'normalized_domain' => 'shop.test']);
        $audit = $domain->websiteAudits()->create(['status' => 'completed', 'finished_at' => now(), 'expires_at' => now()->addDays(7), 'summary' => ['pages_completed' => count($kinds)]]);
        foreach ($kinds as $kind) {
            $url = 'https://shop.test/'.($kind === 'homepage' ? '' : $kind);
            $audit->pages()->create(['url' => $url, 'final_url' => $url, 'url_hash' => hash('sha256', $url), 'kind' => $kind,
                'status' => 'completed', 'fetched_at' => now(), 'evidence' => ['title' => 'Shop']]);
        }

        return $audit;
    }

    private function response(string $url = 'https://shop.test/', string $strategy = 'mobile'): array
    {
        return ['lighthouseResult' => [
            'requestedUrl' => $url, 'finalUrl' => $url, 'fetchTime' => now()->toIso8601String(), 'lighthouseVersion' => '13.0.0',
            'configSettings' => ['formFactor' => $strategy], 'categories' => ['performance' => ['score' => 0.42]],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => 4200, 'numericUnit' => 'millisecond'],
                'cumulative-layout-shift' => ['numericValue' => 0, 'numericUnit' => 'unitless'],
                'unused-javascript' => ['title' => 'Reduce unused JavaScript', 'scoreDisplayMode' => 'numeric', 'score' => 0.2, 'displayValue' => '200 KiB', 'details' => ['overallSavingsMs' => 1200, 'items' => [['url' => 'private-script-detail']]]],
                'screenshot-thumbnails' => ['details' => ['items' => [['data' => 'large-base64-image']]]],
            ],
        ]];
    }

    private function fakeSuccess(): void
    {
        Http::fake([PageSpeedClient::ENDPOINT.'*' => fn ($request) => Http::response($this->response($request['url'], $request['strategy']))]);
    }

    public function test_collects_mobile_home_and_category_without_changing_audit_or_contacts(): void
    {
        $audit = $this->audit();
        $before = $audit->fresh()->toArray();
        $pages = $audit->pages()->get()->toArray();
        $domain = $audit->domain->toArray();
        $this->fakeSuccess();
        $this->assertSame(2, app(PageSpeedDispatcher::class)->request($audit));
        Queue::assertPushed(RunPageSpeedMeasurement::class, 2);
        foreach (PageSpeedMeasurement::all() as $row) {
            $this->assertNull(app(PageSpeedRunner::class)->run($row->id));
            $row->refresh();
            $this->assertSame('completed', $row->status);
            $this->assertSame(42, $row->performance_score);
            $this->assertSame(0, $row->result['lab']['metrics']['cumulative-layout-shift']['value']);
            $this->assertNull($row->result['lab']['metrics']['total-blocking-time']['value']);
            $this->assertSame('unavailable', $row->result['field']['page']['status']);
            $this->assertTrue($row->result['drafting_evidence']['has_lab_speed_concern']);
            $this->assertNull($row->active_key);
            $this->assertStringNotContainsString('large-base64-image', json_encode($row->result));
            $this->assertStringNotContainsString('private-script-detail', json_encode($row->result));
        }
        $this->assertSame($before, $audit->fresh()->toArray());
        $this->assertSame($pages, $audit->pages()->get()->toArray());
        $this->assertSame($domain, $audit->domain->fresh()->toArray());
        Http::assertSentCount(2);
    }

    public function test_reuses_active_and_fresh_results_and_refresh_preserves_history(): void
    {
        $audit = $this->audit(['homepage']);
        $dispatcher = app(PageSpeedDispatcher::class);
        $this->fakeSuccess();
        $this->assertSame(1, $dispatcher->request($audit));
        $this->assertSame(0, $dispatcher->request($audit, true));
        $row = PageSpeedMeasurement::sole();
        app(PageSpeedRunner::class)->run($row->id);
        $this->assertSame(0, $dispatcher->request($audit));
        $this->assertSame(1, $dispatcher->request($audit, true));
        $this->assertSame(2, PageSpeedMeasurement::count());
        $this->assertSame('completed', $row->fresh()->status);
        Queue::assertPushed(RunPageSpeedMeasurement::class, 2);
    }

    public function test_expired_results_recollect_and_desktop_is_optional(): void
    {
        $audit = $this->audit(['homepage']);
        $this->fakeSuccess();
        $dispatcher = app(PageSpeedDispatcher::class);
        $dispatcher->request($audit);
        app(PageSpeedRunner::class)->run(PageSpeedMeasurement::sole()->id);
        $this->travel(8)->days();
        config(['pagespeed.desktop' => true]);
        $this->assertSame(2, $dispatcher->request($audit));
        $this->assertSame(['desktop', 'mobile'], PageSpeedMeasurement::whereNotNull('active_key')->orderBy('strategy')->pluck('strategy')->all());
    }

    public function test_transient_errors_retry_only_the_failed_measurement_and_honor_backoff(): void
    {
        $audit = $this->audit(['homepage']);
        Http::fakeSequence()->push(['error' => ['message' => 'secret-provider-detail']], 503)->push($this->response());
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        $runner = app(PageSpeedRunner::class);
        $this->assertSame(60, $runner->run($row->id));
        $this->assertSame(60, $runner->run($row->id));
        $this->assertSame(1, $row->fresh()->attempts);
        $this->assertNull($row->fresh()->performance_score);
        $this->assertSame('PageSpeed returned HTTP 503.', $row->fresh()->error);
        $this->travel(61)->seconds();
        $this->assertNull($runner->run($row->id));
        $this->assertSame(2, $row->fresh()->attempts);
        $this->assertSame('completed', $row->fresh()->status);
        $this->assertNull($row->fresh()->error);
        Http::assertSentCount(2);
    }

    public function test_quota_cooldown_is_shared_and_does_not_consume_other_measurement_attempts(): void
    {
        $audit = $this->audit();
        Http::fake([PageSpeedClient::ENDPOINT.'*' => Http::response([], 429, ['Retry-After' => '600'])]);
        app(PageSpeedDispatcher::class)->request($audit);
        [$first, $second] = PageSpeedMeasurement::all()->all();
        $runner = app(PageSpeedRunner::class);
        $this->assertSame(600, $runner->run($first->id));
        $this->assertSame(600, $runner->run($second->id));
        $this->assertSame(0, $second->fresh()->attempts);
        Http::assertSentCount(1);
    }

    public function test_request_budgets_and_lock_prevent_unbounded_calls(): void
    {
        config(['pagespeed.requests_per_minute' => 1, 'pagespeed.requests_per_day' => 2]);
        $budget = app(PageSpeedBudget::class);
        $this->assertSame(0, $budget->reserve());
        $this->assertSame(60, $budget->reserve());
        $this->travel(61)->seconds();
        $this->assertSame(0, $budget->reserve());
        $this->travel(61)->seconds();
        $this->assertGreaterThan(86000, $budget->reserve());
        $lock = Cache::lock('pagespeed:budget-lock', 5);
        $lock->get();
        $this->assertSame(5, $budget->reserve());
        $lock->release();
    }

    public function test_google_403_daily_quota_is_retryable_and_cooldown_cannot_be_shortened(): void
    {
        $audit = $this->audit(['homepage']);
        Http::fake([PageSpeedClient::ENDPOINT.'*' => Http::response(['error' => ['errors' => [['reason' => 'dailyLimitExceeded']]]], 403)]);
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        $this->assertSame(86400, app(PageSpeedRunner::class)->run($row->id));
        $this->assertSame('quota_exceeded', $row->fresh()->error_code);
        $budget = app(PageSpeedBudget::class);
        $budget->coolDown(60);
        $this->assertSame(86400, $budget->reserve());
    }

    public function test_streaming_response_limit_stops_writes_before_parsing(): void
    {
        config(['pagespeed.max_response_bytes' => 1024]);
        Http::fake(function ($request, $options) {
            $options['sink']->write(str_repeat('x', 600));
            $options['sink']->write(str_repeat('x', 600));
            $this->fail('Response writes should have stopped.');
        });
        $this->expectException(PageSpeedException::class);
        $this->expectExceptionMessage('exceeded the size limit');
        app(PageSpeedClient::class)->measure('https://shop.test/', 'shop.test', 'mobile');
    }

    public function test_invalid_json_retries_and_stale_results_are_never_marked_fresh(): void
    {
        $audit = $this->audit(['homepage']);
        $stale = $this->response();
        $stale['lighthouseResult']['fetchTime'] = now()->subDays(8)->toIso8601String();
        Http::fakeSequence()->push('not-json')->push($stale);
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        $runner = app(PageSpeedRunner::class);
        $this->assertSame(60, $runner->run($row->id));
        $this->assertSame('invalid_response', $row->fresh()->error_code);
        $this->travel(61)->seconds();
        $runner->run($row->id);
        $this->assertSame('stale_result', $row->fresh()->error_code);
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertNull($row->fresh()->performance_score);
    }

    public function test_partial_audit_measures_only_successful_pages_and_desktop_has_separate_results(): void
    {
        config(['pagespeed.desktop' => true]);
        $audit = $this->audit();
        $audit->update(['status' => 'partial']);
        $audit->pages()->where('kind', 'category')->update(['status' => 'failed']);
        $this->fakeSuccess();
        $this->assertSame(2, app(PageSpeedDispatcher::class)->request($audit));
        foreach (PageSpeedMeasurement::all() as $row) {
            app(PageSpeedRunner::class)->run($row->id);
            $this->assertSame($row->strategy, $row->fresh()->result['lab']['strategy']);
            $this->assertSame('homepage', $row->page->kind);
        }
        $this->assertSame('partial', $audit->fresh()->status);
    }

    public function test_retries_stop_after_three_attempts_and_failure_is_cached(): void
    {
        $audit = $this->audit(['homepage']);
        Http::fake([PageSpeedClient::ENDPOINT.'*' => Http::response([], 500)]);
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        for ($i = 0; $i < 3; $i++) {
            app(PageSpeedRunner::class)->run($row->id);
            $this->travel(301)->seconds();
        }
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame(3, $row->fresh()->attempts);
        $this->assertNull($row->fresh()->performance_score);
        $this->assertNull($row->fresh()->active_key);
        $this->assertSame(0, app(PageSpeedDispatcher::class)->request($audit));
        app(PageSpeedRunner::class)->run($row->id);
        Http::assertSentCount(3);
    }

    public function test_permanent_errors_do_not_retry_and_api_key_is_not_persisted(): void
    {
        config(['pagespeed.api_key' => 'test-api-secret']);
        $audit = $this->audit(['homepage']);
        Http::fake([PageSpeedClient::ENDPOINT.'*' => Http::response(['error' => ['message' => 'test-api-secret']], 403)]);
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        $this->assertNull(app(PageSpeedRunner::class)->run($row->id));
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertStringNotContainsString('test-api-secret', $row->fresh()->toJson());
        Http::assertSent(fn ($request) => $request['key'] === 'test-api-secret' && $request['category'] === 'performance' && $request['strategy'] === 'mobile');
    }

    public function test_network_exception_does_not_leak_request_url_or_key(): void
    {
        $audit = $this->audit(['homepage']);
        Http::fake(fn () => throw new ConnectionException('https://google.test/?key=secret'));
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        app(PageSpeedRunner::class)->run($row->id);
        $this->assertSame('connection_failed', $row->fresh()->error_code);
        $this->assertStringNotContainsString('secret', $row->fresh()->toJson());
    }

    #[DataProvider('invalidResults')]
    public function test_rejects_invalid_results_without_inventing_scores(string $path, mixed $value, string $reason): void
    {
        $data = $this->response();
        data_set($data, $path, $value);
        try {
            app(PageSpeedResultParser::class)->parse($data, 'https://shop.test/', 'shop.test', 'mobile');
            $this->fail('Expected the measurement to be rejected.');
        } catch (PageSpeedException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    public static function invalidResults(): array
    {
        return [
            ['lighthouseResult.categories.performance.score', null, 'missing_score'],
            ['lighthouseResult.categories.performance.score', '0.5', 'missing_score'],
            ['lighthouseResult.categories.performance.score', 2, 'missing_score'],
            ['lighthouseResult.runtimeError', ['code' => 'NO_FCP'], 'lighthouse_error'],
            ['lighthouseResult.finalUrl', 'https://other.test/', 'url_mismatch'],
            ['lighthouseResult.finalUrl', 'http://shop.test/', 'url_mismatch'],
            ['lighthouseResult.requestedUrl', 'https://shop.test/other', 'url_mismatch'],
            ['lighthouseResult.configSettings.formFactor', 'desktop', 'device_mismatch'],
            ['lighthouseResult.fetchTime', null, 'invalid_timestamp'],
        ];
    }

    public function test_zero_is_valid_and_field_origin_is_not_presented_as_url_data(): void
    {
        $data = $this->response('https://shop.test/category');
        $data['lighthouseResult']['categories']['performance']['score'] = 0;
        $data['loadingExperience'] = ['id' => 'https://shop.test/', 'origin_fallback' => true, 'metrics' => ['CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 12, 'category' => 'AVERAGE']]];
        $data['originLoadingExperience'] = ['id' => 'https://shop.test/', 'metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 2800, 'category' => 'AVERAGE']]];
        $parsed = app(PageSpeedResultParser::class)->parse($data, 'https://shop.test/category', 'shop.test', 'mobile');
        $this->assertSame(0, $parsed['performance_score']);
        $this->assertSame('origin', $parsed['result']['field']['page']['scope']);
        $this->assertSame('origin', $parsed['result']['field']['origin']['scope']);
        $this->assertSame(12, $parsed['result']['field']['page']['metrics']['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile']);
    }

    public function test_good_score_produces_no_speed_concern_and_threshold_is_configurable(): void
    {
        $data = $this->response();
        $data['lighthouseResult']['categories']['performance']['score'] = 0.95;
        $parser = app(PageSpeedResultParser::class);
        $parsed = $parser->parse($data, 'https://shop.test/', 'shop.test', 'mobile');
        $this->assertFalse($parsed['result']['drafting_evidence']['has_lab_speed_concern']);
        $this->assertNull($parsed['result']['drafting_evidence']['observation']);
        config(['pagespeed.good_score_from' => 96]);
        $this->assertSame('needs_improvement', $parser->parse($data, 'https://shop.test/', 'shop.test', 'mobile')['result']['lab']['rating']);
    }

    public function test_disabled_collection_and_private_dns_make_no_api_request(): void
    {
        $audit = $this->audit(['homepage']);
        app(PageSpeedDispatcher::class)->request($audit);
        config(['pagespeed.enabled' => false]);
        app(PageSpeedRunner::class)->run(PageSpeedMeasurement::sole()->id);
        $this->assertSame('disabled', PageSpeedMeasurement::sole()->error_code);
        config(['pagespeed.enabled' => true]);
        app(PageSpeedDispatcher::class)->request($audit, true);
        $this->mock(PublicDnsResolver::class)->shouldReceive('resolve')->andThrow(new FetchException('Non-public address'));
        app(PageSpeedRunner::class)->run(PageSpeedMeasurement::latest('id')->first()->id);
        $this->assertSame('unsafe_target', PageSpeedMeasurement::latest('id')->first()->error_code);
        Http::assertNothingSent();
    }

    public function test_active_lease_is_not_reclaimed_and_recovery_exhausts_timed_out_attempts(): void
    {
        $audit = $this->audit(['homepage']);
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        $row->update(['lease_token' => 'old-worker', 'lease_until' => now()->addSeconds(85), 'attempts' => 3, 'status' => 'running']);
        $runner = app(PageSpeedRunner::class);
        $this->assertNull($runner->run($row->id));
        $this->assertSame('old-worker', $row->fresh()->lease_token);
        $this->travel(301)->seconds();
        $this->assertSame(1, app(PageSpeedDispatcher::class)->recover(25));
        $this->assertSame(0, app(PageSpeedDispatcher::class)->recover(25));
        $runner->run($row->id);
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame('attempt_limit', $row->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_worker_cannot_overwrite_a_new_lease(): void
    {
        $audit = $this->audit(['homepage']);
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        Http::fake(function () use ($row) {
            $row->update(['lease_token' => 'new-worker']);

            return Http::response($this->response());
        });
        app(PageSpeedRunner::class)->run($row->id);
        $this->assertSame('new-worker', $row->fresh()->lease_token);
        $this->assertNull($row->fresh()->performance_score);
    }

    public function test_batch_limits_measurements_and_uses_only_the_latest_finished_audit(): void
    {
        $old = $this->audit();
        $old->domain->update(['domain' => 'old.test', 'normalized_domain' => 'old.test']);
        $old->domain->websiteAudits()->create(['status' => 'running', 'active_domain_id' => $old->domain_id]);
        $eligible = $this->audit();
        $this->artisan('websites:pagespeed', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(1, PageSpeedMeasurement::count());
        $this->assertSame($eligible->id, PageSpeedMeasurement::sole()->page->website_audit_id);
        $this->artisan('websites:pagespeed', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(2, PageSpeedMeasurement::count());
        $this->artisan('websites:pagespeed', ['--limit' => 1])->assertSuccessful();
        $this->assertSame(2, PageSpeedMeasurement::count());
        $this->artisan('websites:pagespeed', ['--refresh' => true])->assertFailed();
    }

    public function test_released_job_uses_durable_backoff_and_oversized_response_fails(): void
    {
        $audit = $this->audit(['homepage']);
        app(PageSpeedDispatcher::class)->request($audit);
        $row = PageSpeedMeasurement::sole();
        $row->update(['next_attempt_at' => now()->addSeconds(120)]);
        $job = (new RunPageSpeedMeasurement($row->id))->withFakeQueueInteractions();
        $job->handle(app(PageSpeedRunner::class));
        $job->assertReleased(120);
        $this->travel(121)->seconds();
        config(['pagespeed.max_response_bytes' => 1024]);
        Http::fake([PageSpeedClient::ENDPOINT.'*' => Http::response(str_repeat('x', 1025))]);
        app(PageSpeedRunner::class)->run($row->id);
        $this->assertSame('response_too_large', $row->fresh()->error_code);
        $this->assertSame('failed', $row->fresh()->status);
    }

    public function test_admin_can_queue_and_read_results_but_readonly_user_cannot_refresh(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $audit = $this->audit(['homepage']);
        Livewire::test(DomainPageSpeed::class, ['domainId' => $audit->domain_id])->assertSee('No PageSpeed measurements')->call('measure')->assertSee('Queued');
        $this->fakeSuccess();
        app(PageSpeedRunner::class)->run(PageSpeedMeasurement::sole()->id);
        Livewire::test(DomainPageSpeed::class, ['domainId' => $audit->domain_id])->assertSee('Lighthouse performance score: 42 out of 100')->assertSee('Metrics')->assertSee('Unavailable');
        Gate::policy(Domain::class, PageSpeedReadOnlyPolicy::class);
        Livewire::test(DomainPageSpeed::class, ['domainId' => $audit->domain_id])->call('measure', true)->assertForbidden();
        $this->assertSame(1, PageSpeedMeasurement::count());
    }
}

class PageSpeedReadOnlyPolicy
{
    public function view(User $user, Domain $domain): bool
    {
        return true;
    }

    public function update(User $user, Domain $domain): bool
    {
        return false;
    }
}
