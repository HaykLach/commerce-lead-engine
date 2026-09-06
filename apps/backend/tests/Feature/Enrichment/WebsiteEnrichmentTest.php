<?php

declare(strict_types=1);

namespace Tests\Feature\Enrichment;

use App\Jobs\RunWebsiteAudit;
use App\Livewire\DomainContacts;
use App\Livewire\DomainWebsiteAudit;
use App\Models\Domain;
use App\Models\PageClassification;
use App\Models\User;
use App\Models\WebsiteAudit;
use App\Services\Contacts\ContactIngestionService;
use App\Services\Contacts\ContactSelectionService;
use App\Services\Enrichment\AuditUrl;
use App\Services\Enrichment\CurlPageTransport;
use App\Services\Enrichment\FetchException;
use App\Services\Enrichment\HtmlPageAnalyzer;
use App\Services\Enrichment\PublicDnsResolver;
use App\Services\Enrichment\PublicPageFetcher;
use App\Services\Enrichment\WebsiteAuditDispatcher;
use App\Services\Enrichment\WebsiteAuditRunner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WebsiteEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['enrichment.pace_milliseconds' => 0]);
        Queue::fake();
    }

    private function domain(): Domain
    {
        return Domain::factory()->create(['domain' => 'shop.test', 'normalized_domain' => 'shop.test', 'metadata' => ['site_acceptable' => true]]);
    }

    private function fakePages(array $pages): object
    {
        $fake = new class($pages) extends PublicPageFetcher
        {
            public array $calls = [];

            public function __construct(public array $pages) {}

            public function fetch(string $url, string $domain): array
            {
                $this->calls[] = $url;
                $response = $this->pages[$url] ?? new FetchException('HTTP 404', false, 404);
                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return ['url' => $url, 'status' => 200, 'html' => $response];
            }
        };
        $this->app->instance(PublicPageFetcher::class, $fake);

        return $fake;
    }

    public function test_audit_fetches_selected_pages_persists_contacts_and_keeps_discovery_separate(): void
    {
        $domain = $this->domain();
        $fetcher = $this->fakePages([
            'https://shop.test/' => '<title>My shop</title><a href="/contact">Contact</a><a href="/category/shoes">Shoes</a><a href="/product/shoe">Product</a>',
            'https://shop.test/contact' => '<h1>Contact us</h1><a href="mailto:info@shop.test">Email us</a>',
            'https://shop.test/category/shoes' => '<h1>Shoes</h1>',
            'https://shop.test/product/shoe' => '<title>Shoe</title><img src="shoe.jpg"><img alt="" src="decoration.jpg">',
        ]);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        app(WebsiteAuditRunner::class)->run($audit->id);
        $audit->refresh();
        $this->assertSame('completed', $audit->status);
        $this->assertNull($audit->active_domain_id);
        $this->assertCount(4, $fetcher->calls);
        $this->assertSame(['homepage', 'contact', 'category', 'product'], $audit->summary['coverage']);
        $this->assertSame(1, $audit->pages()->where('kind', 'product')->sole()->evidence['metadata']['images_without_alt_attribute']);
        $this->assertSame('info@shop.test', $domain->fresh()->primaryContact->email);
        $this->assertSame($audit->id, $domain->fresh()->contacts_audit_id);
        $this->assertSame($audit->id, $domain->contacts()->sole()->sources()->sole()->website_audit_id);
        $this->assertSame(0, PageClassification::query()->count());
    }

    public function test_duplicate_requests_and_fresh_audits_do_not_enqueue_more_work(): void
    {
        $domain = $this->domain();
        $this->fakePages(['https://shop.test/' => '<title>Shop</title>']);
        $dispatcher = app(WebsiteAuditDispatcher::class);
        $audit = $dispatcher->request($domain);
        $this->assertSame($audit->id, $dispatcher->request($domain, true)->id);
        app(WebsiteAuditRunner::class)->run($audit->id);
        $this->assertSame($audit->id, $dispatcher->request($domain)->id);
        Queue::assertPushed(RunWebsiteAudit::class, 1);
        $this->assertNotSame($audit->id, $dispatcher->request($domain, true)->id);
        Queue::assertPushed(RunWebsiteAudit::class, 2);
    }

    public function test_a_contact_link_discovered_on_the_about_page_is_followed(): void
    {
        $domain = $this->domain();
        $fetcher = $this->fakePages([
            'https://shop.test/' => '<a href="/about">About us</a>',
            'https://shop.test/about' => '<a href="/contact">Contact</a>',
            'https://shop.test/contact' => '<p>info@shop.test</p>',
        ]);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        app(WebsiteAuditRunner::class)->run($audit->id);
        $this->assertCount(3, $fetcher->calls);
        $this->assertSame('info@shop.test', $domain->fresh()->primaryContact->email);
    }

    public function test_transient_failures_stop_at_the_page_attempt_limit(): void
    {
        $domain = $this->domain();
        $fetcher = $this->fakePages(['https://shop.test/' => new FetchException('HTTP 503', true, 503)]);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                app(WebsiteAuditRunner::class)->run($audit->id);
            } catch (RuntimeException $exception) {
                $this->assertSame('Retrying transient website audit failures.', $exception->getMessage());
            }
        }
        $this->assertSame('failed', $audit->fresh()->status);
        $this->assertNull($audit->fresh()->active_domain_id);
        $this->assertCount(3, $fetcher->calls);
        $this->assertSame(3, $audit->pages()->sole()->attempts);
    }

    public function test_page_budget_is_enforced_and_action_links_are_not_followed(): void
    {
        config(['enrichment.max_pages' => 3]);
        $domain = $this->domain();
        $fetcher = $this->fakePages([
            'https://shop.test/' => '<a href="/contact">Contact</a><a href="/category/shoes">Category</a><a href="/product/a">Product</a><a href="/about">About</a><a href="/cart?add-to-cart=1">Product</a>',
            'https://shop.test/contact' => '<p>Contact</p>',
            'https://shop.test/category/shoes' => '<p>Category</p>',
        ]);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        app(WebsiteAuditRunner::class)->run($audit->id);
        $this->assertSame(['https://shop.test/', 'https://shop.test/contact', 'https://shop.test/category/shoes'], $fetcher->calls);
        $this->assertSame(3, $audit->pages()->count());
    }

    public function test_transient_failures_retry_only_failed_pages_and_keep_observation_dates(): void
    {
        $this->travelTo(now()->startOfDay());
        $domain = $this->domain();
        $fetcher = $this->fakePages([
            'https://shop.test/' => '<p>info@shop.test</p><a href="/contact">Contact</a>',
            'https://shop.test/contact' => new FetchException('HTTP 503', true, 503),
        ]);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        $runner = app(WebsiteAuditRunner::class);
        try {
            $runner->run($audit->id);
            $this->fail('Expected a retry');
        } catch (RuntimeException $exception) {
            $this->assertSame('Retrying transient website audit failures.', $exception->getMessage());
        }
        $this->assertNull($audit->fresh()->lease_token);
        $this->assertSame('queued', $audit->fresh()->status);
        $observed = now()->toDateTimeString();
        $this->travel(2)->minutes();
        $fetcher->pages['https://shop.test/contact'] = '<h1>Contact form</h1>';
        $runner->run($audit->id);
        $this->assertSame(['https://shop.test/', 'https://shop.test/contact', 'https://shop.test/contact'], $fetcher->calls);
        $this->assertSame('completed', $audit->fresh()->status);
        $this->assertSame($observed, $domain->contacts()->sole()->last_seen_at->toDateTimeString());
    }

    public function test_permanent_failure_produces_partial_audit_and_does_not_auto_select(): void
    {
        $domain = $this->domain();
        $this->fakePages(['https://shop.test/' => '<p>info@shop.test</p><a href="/contact">Contact</a>']);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        app(WebsiteAuditRunner::class)->run($audit->id);
        $this->assertSame('partial', $audit->fresh()->status);
        $this->assertSame(1, $domain->contacts()->count());
        $this->assertNull($domain->fresh()->primary_contact_id);
        $this->assertSame(1, $audit->pages()->where('kind', 'contact')->sole()->attempts);
    }

    public function test_unexpected_failures_release_the_lease_and_recovery_keeps_completed_pages(): void
    {
        $domain = $this->domain();
        $fetcher = $this->fakePages(['https://shop.test/' => new RuntimeException('Unexpected failure')]);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        try {
            app(WebsiteAuditRunner::class)->run($audit->id);
            $this->fail('Expected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unexpected failure', $exception->getMessage());
        }
        $this->assertNull($audit->fresh()->lease_until);
        $fetcher->pages['https://shop.test/'] = '<p>Recovered</p>';
        app(WebsiteAuditRunner::class)->run($audit->id);
        $this->assertSame('completed', $audit->fresh()->status);
    }

    public function test_live_lease_prevents_duplicate_processing_and_terminal_delivery_is_a_noop(): void
    {
        $domain = $this->domain();
        $fetcher = $this->fakePages(['https://shop.test/' => '<p>Shop</p>']);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        $audit->update(['lease_until' => now()->addMinute()]);
        app(WebsiteAuditRunner::class)->run($audit->id);
        $this->assertSame([], $fetcher->calls);
        $audit->update(['lease_until' => now()->subMinute()]);
        app(WebsiteAuditRunner::class)->run($audit->id);
        app(WebsiteAuditRunner::class)->run($audit->id);
        $this->assertCount(1, $fetcher->calls);
    }

    public function test_manual_contact_choice_survives_audit_and_old_classification_import(): void
    {
        $domain = $this->domain();
        $selection = app(ContactSelectionService::class);
        $manual = $selection->addManual($domain, 'hello@shop.test');
        $selection->select($domain, $manual->id);
        $old = PageClassification::query()->create(['domain_id' => $domain->id, 'url' => 'https://shop.test/', 'classified_at' => now()->subDay(), 'classification_metadata' => [
            'pages_scanned' => [['url' => 'https://shop.test/', 'contacts' => ['version' => 1, 'status' => 'collected', 'emails' => []]]],
        ]]);
        $this->fakePages(['https://shop.test/' => '<p>info@shop.test</p>']);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain);
        app(WebsiteAuditRunner::class)->run($audit->id);
        app(ContactIngestionService::class)->ingest($old);
        $this->assertSame($manual->id, $domain->fresh()->primary_contact_id);
        $this->assertSame($audit->id, $domain->fresh()->contacts_audit_id);
    }

    public function test_batch_targets_accepted_domains_and_recovers_stale_queued_work(): void
    {
        $domain = $this->domain();
        Domain::factory()->create(['metadata' => ['site_acceptable' => false]]);
        $this->artisan('websites:enrich', ['--limit' => 1])->assertSuccessful();
        $audit = $domain->websiteAudits()->sole();
        $this->artisan('websites:enrich', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(RunWebsiteAudit::class, 1);
        $audit->forceFill(['updated_at' => now()->subMinutes(10)])->save();
        $this->artisan('websites:enrich', ['--limit' => 1])->assertSuccessful();
        Queue::assertPushed(RunWebsiteAudit::class, 2);
        $this->assertSame(1, WebsiteAudit::query()->count());
        $this->artisan('websites:enrich', ['--refresh' => true])->assertFailed();
    }

    public function test_php_parser_collects_metadata_and_contacts_without_hidden_data(): void
    {
        $html = '<html><head><title>Armenian shop</title><meta name="description" content="Handmade products"></head><body>'
            .'<h1>Մեր խանութը</h1><p>info@shop.test</p><p>sales@shop.test.</p>'
            .'<script>"secret@shop.test"</script><div hidden>hidden@shop.test</div>'
            .'<script type="application/ld+json">{"@type":"Store","email":"info@shop.test","name":"My Shop"}</script>'
            .'<a href="mailto:info%40shop.test?cc=wrong@agency.test">Email us</a><a href="/contact">Contact</a>'
            .'<form action="/send?token=secret"><input name="token" type="hidden" value="secret"><input type="email" name="email"><textarea name="message"></textarea></form></body></html>';
        $evidence = app(HtmlPageAnalyzer::class)->analyze($html, 'https://shop.test/');
        $this->assertSame('Armenian shop', $evidence['metadata']['title']);
        $this->assertSame(['Մեր խանութը'], $evidence['metadata']['h1']);
        $this->assertNull($evidence['metadata']['canonical_url']);
        $this->assertSame(['info@shop.test', 'sales@shop.test'], array_column($evidence['contacts']['emails'], 'value'));
        $this->assertCount(3, $evidence['contacts']['emails'][0]['source_methods']);
        $this->assertSame(['email', 'message'], $evidence['contacts']['forms'][0]['field_names']);
        $this->assertStringNotContainsString('secret', json_encode($evidence));
        $this->assertSame(['https://shop.test/contact'], $evidence['contacts']['contact_page_urls']);
    }

    #[DataProvider('unsafeAddresses')]
    public function test_private_reserved_and_transition_ips_are_rejected(string $ip): void
    {
        $this->assertFalse(app(PublicDnsResolver::class)->isPublic($ip));
    }

    public static function unsafeAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], ['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '192.0.2.1', '224.0.0.1', '::1', 'fc00::1', '::ffff:127.0.0.1', '2002:7f00:1::', '2001:db8::1']);
    }

    #[DataProvider('unsafeUrls')]
    public function test_non_web_credentials_and_ambiguous_hosts_are_rejected(string $url): void
    {
        $this->assertNull(AuditUrl::normalize($url));
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], ['file:///etc/passwd', 'https://user:pass@shop.test/', 'https://shop.test:8080/', 'http://2130706433/', 'http://0177.0.0.1/', 'http://0x7f000001/', 'https://shop.test\\@other.test/']);
    }

    public function test_redirects_resolve_and_pin_each_hop_and_cross_domain_redirects_are_not_fetched(): void
    {
        $dns = $this->mock(PublicDnsResolver::class);
        $dns->shouldReceive('resolve')->with('shop.test')->once()->andReturn('8.8.8.8');
        $dns->shouldReceive('resolve')->with('www.shop.test')->once()->andReturn('1.1.1.1');
        $transport = $this->mock(CurlPageTransport::class);
        $transport->shouldReceive('get')->with('https://shop.test/', '8.8.8.8', \Mockery::type('float'))->once()
            ->andReturn(['status' => 301, 'headers' => ['location' => 'https://www.shop.test/'], 'body' => '']);
        $transport->shouldReceive('get')->with('https://www.shop.test/', '1.1.1.1', \Mockery::type('float'))->once()
            ->andReturn(['status' => 302, 'headers' => ['location' => 'https://other.test/'], 'body' => '']);
        $this->expectException(FetchException::class);
        app(PublicPageFetcher::class)->fetch('https://shop.test/', 'shop.test');
    }

    public function test_dns_private_address_blocks_transport_before_any_connection(): void
    {
        $dns = new class extends PublicDnsResolver
        {
            protected function lookup(string $host): array
            {
                return ['8.8.8.8', '127.0.0.1'];
            }
        };
        $this->app->instance(PublicDnsResolver::class, $dns);
        $this->mock(CurlPageTransport::class)->shouldNotReceive('get');
        $this->expectException(FetchException::class);
        app(PublicPageFetcher::class)->fetch('https://shop.test/', 'shop.test');
    }

    public function test_dns_is_revalidated_after_a_same_host_redirect(): void
    {
        $dns = $this->mock(PublicDnsResolver::class);
        $dns->shouldReceive('resolve')->with('shop.test')->once()->ordered()->andReturn('8.8.8.8');
        $dns->shouldReceive('resolve')->with('shop.test')->once()->ordered()->andThrow(new FetchException('Non-public address'));
        $transport = $this->mock(CurlPageTransport::class);
        $transport->shouldReceive('get')->once()->andReturn(['status' => 302, 'headers' => ['location' => '/contact'], 'body' => '']);
        $this->expectException(FetchException::class);
        app(PublicPageFetcher::class)->fetch('https://shop.test/', 'shop.test');
    }

    public function test_oversized_html_is_not_parsed(): void
    {
        config(['enrichment.max_html_bytes' => 1024]);
        $this->expectException(FetchException::class);
        app(HtmlPageAnalyzer::class)->analyze(str_repeat('x', 1025), 'https://shop.test/');
    }

    public function test_admin_action_queues_audit_and_displays_completed_findings_and_contacts(): void
    {
        $domain = $this->domain();
        $this->fakePages(['https://shop.test/' => '<title>Shop title</title><p>info@shop.test</p>']);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Livewire::test(DomainWebsiteAudit::class, ['domainId' => $domain->id])->assertSee('No website audit yet.')->call('analyze')->assertSee('Queued');
        app(WebsiteAuditRunner::class)->run($domain->websiteAudits()->sole()->id);
        Livewire::test(DomainWebsiteAudit::class, ['domainId' => $domain->id])->assertSee('Completed')->assertSee('Shop title');
        Livewire::test(DomainContacts::class, ['domainId' => $domain->id])->assertSee('info@shop.test')->assertSee('Automatically selected')->assertDontSee('No contact extraction results yet.');
        Gate::policy(Domain::class, AuditReadOnlyPolicy::class);
        Livewire::test(DomainWebsiteAudit::class, ['domainId' => $domain->id])->call('analyze', true)->assertForbidden();
        $this->assertSame(1, $domain->websiteAudits()->count());
    }
}

class AuditReadOnlyPolicy
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
