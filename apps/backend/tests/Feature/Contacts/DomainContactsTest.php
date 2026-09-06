<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Filament\Resources\DomainResource\Pages\ViewDomain;
use App\Livewire\DomainContacts;
use App\Models\Domain;
use App\Models\DomainContact;
use App\Models\DomainContactSource;
use App\Models\PageClassification;
use App\Models\User;
use App\Services\Contacts\ContactIngestionService;
use App\Services\Contacts\ContactScanReader;
use App\Services\Contacts\ContactSelectionService;
use App\Services\InternalApi\PageClassificationIngestionService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DomainContactsTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): Domain
    {
        return Domain::factory()->create(['domain' => 'shop.test', 'normalized_domain' => 'shop.test']);
    }

    private function payload(Domain $domain, array $pages, string $at = '2026-09-06T10:00:00Z'): array
    {
        return [
            'domain_id' => $domain->id,
            'domain' => $domain->domain,
            'classified_at' => $at,
            'classification_metadata' => [
                'sampled_urls' => array_keys($pages),
                'pages_scanned' => collect($pages)->map(fn (array $emails, string $url): array => [
                    'url' => $url, 'final_url' => $url,
                    'contacts' => [
                        'version' => 1, 'status' => 'collected', 'truncated' => false,
                        'emails' => array_map(fn (string $email): array => [
                            'value' => $email, 'source_url' => $url, 'source_methods' => ['mailto'],
                            'purpose_hint' => 'unknown', 'domain_matches_page' => true,
                        ], $emails),
                        'forms' => [], 'contact_page_urls' => [],
                    ],
                ])->values()->all(),
            ],
        ];
    }

    private function scan(Domain $domain, array $emails, string $at = '2026-09-06T10:00:00Z'): PageClassification
    {
        return app(PageClassificationIngestionService::class)->store($this->payload($domain, ['https://shop.test/' => $emails], $at));
    }

    private function login(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    public function test_api_deduplicates_contacts_and_preserves_multiple_sources(): void
    {
        $domain = $this->domain();
        $payload = $this->payload($domain, [
            'https://shop.test/' => ['INFO@SHOP.TEST'],
            'https://shop.test/contact' => ['info@shop.test'],
        ]);
        $this->postJson('/api/v1/internal/page-classifications', $payload)->assertCreated();
        $contact = $domain->contacts()->sole();
        $this->assertSame('info@shop.test', $contact->email);
        $this->assertSame('business', $contact->suggested_purpose);
        $this->assertCount(2, $contact->sources);
        $this->assertSame('unknown', $contact->sources->first()->original_hint);
        $this->assertSame($contact->id, $domain->fresh()->primary_contact_id);
        $this->assertSame('automatic', $domain->fresh()->contact_selection_mode);
    }

    #[DataProvider('unsuitableEmails')]
    public function test_a_single_unsuitable_email_is_not_automatically_selected(string $email): void
    {
        $domain = $this->domain();
        $this->scan($domain, [$email]);
        $this->assertCount(1, $domain->contacts);
        $this->assertNull($domain->fresh()->primary_contact_id);
    }

    public static function unsuitableEmails(): array
    {
        return array_map(fn ($email) => [$email], [
            'privacy@shop.test', 'noreply@shop.test', 'no-reply@shop.test',
            'jobs@shop.test', 'support@shop.test', 'hayk@shop.test', 'info@agency.test',
        ]);
    }

    public function test_multiple_suitable_emails_require_selection_and_manual_choice_survives_rescans(): void
    {
        $domain = $this->domain();
        $first = $this->scan($domain, ['info@shop.test', 'sales@shop.test']);
        $this->assertNull($domain->fresh()->primary_contact_id);
        $sales = $domain->contacts()->where('email', 'sales@shop.test')->sole();
        app(ContactSelectionService::class)->select($domain, $sales->id);
        $this->scan($domain, [], '2026-09-07T10:00:00Z');
        app(ContactIngestionService::class)->ingest($first);
        $this->assertSame($sales->id, $domain->fresh()->primary_contact_id);
        $this->assertSame('manual', $domain->fresh()->contact_selection_mode);
    }

    public function test_manual_purpose_and_exclusion_are_not_overwritten(): void
    {
        $domain = $this->domain();
        $first = $this->scan($domain, ['info@shop.test']);
        $contact = $domain->contacts()->sole();
        $selection = app(ContactSelectionService::class);
        $selection->updatePurpose($domain, $contact->id, 'customer_support');
        $selection->exclude($domain, $contact->id, true);
        $this->scan($domain, ['info@shop.test'], '2026-09-07T10:00:00Z');
        app(ContactIngestionService::class)->ingest($first);
        $contact->refresh();
        $this->assertSame('customer_support', $contact->effectivePurpose());
        $this->assertSame('excluded', $contact->review_status);
        $this->assertNull($domain->fresh()->primary_contact_id);
        $this->assertSame('2026-09-06 10:00:00', $contact->first_seen_at->toDateTimeString());
        $this->assertSame('2026-09-07 10:00:00', $contact->last_seen_at->toDateTimeString());
        $this->assertSame(1, $contact->sources()->count());
        $this->assertSame('2026-09-07 10:00:00', $contact->sources()->sole()->last_seen_at->toDateTimeString());
    }

    public function test_clearing_selection_is_preserved_and_blocking_primary_clears_it(): void
    {
        $domain = $this->domain();
        $scan = $this->scan($domain, ['info@shop.test']);
        $contact = $domain->contacts()->sole();
        $selection = app(ContactSelectionService::class);
        $selection->select($domain, null);
        app(ContactIngestionService::class)->ingest($scan);
        $this->assertNull($domain->fresh()->primary_contact_id);
        $selection->select($domain, $contact->id);
        $selection->updatePurpose($domain, $contact->id, 'legal_privacy');
        $this->assertNull($domain->fresh()->primary_contact_id);
    }

    public function test_empty_new_scan_clears_only_automatic_selection_and_old_import_cannot_restore_it(): void
    {
        $domain = $this->domain();
        $old = $this->scan($domain, ['info@shop.test']);
        $latest = $this->scan($domain, [], '2026-09-07T10:00:00Z');
        app(ContactIngestionService::class)->ingest($old);
        $this->assertNull($domain->fresh()->primary_contact_id);
        $this->assertSame($latest->id, $domain->fresh()->contacts_classification_id);
        $this->assertSame(1, $domain->contacts()->count());
    }

    public function test_partial_scan_does_not_automatically_select_its_only_email(): void
    {
        $domain = $this->domain();
        $payload = $this->payload($domain, ['https://shop.test/' => ['info@shop.test']]);
        $payload['classification_metadata']['sampled_urls'][] = 'https://shop.test/contact';
        $scan = app(PageClassificationIngestionService::class)->store($payload);
        $this->assertSame('partial', app(ContactScanReader::class)->read($scan)['status']);
        $this->assertNull($domain->fresh()->primary_contact_id);
    }

    public function test_import_command_is_repeatable_and_can_target_a_domain(): void
    {
        $domain = $this->domain();
        $other = Domain::factory()->create();
        PageClassification::query()->create($this->payload($domain, ['https://shop.test/' => ['info@shop.test']]) + ['url' => 'https://shop.test/']);
        PageClassification::query()->create($this->payload($other, ['https://other.test/' => ['info@other.test']]) + ['url' => 'https://other.test/']);
        $this->artisan('contacts:import', ['--domain-id' => $domain->id])->assertSuccessful();
        $this->artisan('contacts:import', ['--domain-id' => $domain->id])->assertSuccessful();
        $this->assertSame(1, DomainContact::query()->count());
        $this->assertSame(1, DomainContactSource::query()->count());
        $this->artisan('contacts:import', ['--domain-id' => 'invalid'])->assertFailed();
    }

    public function test_contact_failure_rolls_back_the_classification(): void
    {
        $domain = $this->domain();
        $this->mock(ContactIngestionService::class)->shouldReceive('ingest')->once()->andThrow(new \RuntimeException('Import failed'));
        try {
            $this->scan($domain, ['info@shop.test']);
            $this->fail('Expected import failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Import failed', $exception->getMessage());
        }
        $this->assertSame(0, PageClassification::query()->count());
    }

    public function test_domain_page_renders_contacts_and_livewire_saves_radio_selection(): void
    {
        $this->login();
        $domain = $this->domain();
        $this->scan($domain, ['info@shop.test', 'sales@shop.test']);
        Livewire::test(ViewDomain::class, ['record' => $domain->id])->assertSee('Contacts')->assertSee('info@shop.test');
        $contact = $domain->contacts()->where('email', 'sales@shop.test')->sole();
        Livewire::test(DomainContacts::class, ['domainId' => $domain->id])
            ->assertSeeHtml('type="radio"')->call('selectContact', $contact->id)
            ->assertHasNoErrors()->assertSee('Manually selected')
            ->call('showSources', $contact->id)->assertSee('Original hint:')
            ->call('updatePurpose', $contact->id, 'business')->assertSee('Manually corrected');
        $this->assertSame($contact->id, $domain->fresh()->primary_contact_id);
    }

    public function test_livewire_adds_manual_email_and_does_not_restore_an_excluded_duplicate(): void
    {
        $this->login();
        $domain = $this->domain();
        $component = Livewire::test(DomainContacts::class, ['domainId' => $domain->id]);
        $component->set('newEmail', 'HELLO@SHOP.TEST')->call('addEmail')->assertHasNoErrors()->assertSee('hello@shop.test');
        $contact = $domain->contacts()->sole();
        $component->call('excludeContact', $contact->id)
            ->set('newEmail', 'hello@shop.test')->call('addEmail')->assertHasNoErrors();
        $this->assertSame(1, $domain->contacts()->count());
        $this->assertSame('excluded', $contact->fresh()->review_status);
        $component->call('selectContact', $contact->id)->assertHasErrors('contact');
        $component->call('restoreContact', $contact->id)->call('selectContact', $contact->id)->assertHasNoErrors();
    }

    public function test_no_email_and_form_only_scans_have_useful_empty_states(): void
    {
        $this->login();
        $domain = $this->domain();
        Livewire::test(DomainContacts::class, ['domainId' => $domain->id])->assertSee('No contact extraction results yet.');
        $payload = $this->payload($domain, ['https://shop.test/contact' => []]);
        $payload['classification_metadata']['pages_scanned'][0]['contacts']['forms'] = [['source_url' => 'https://shop.test/contact']];
        app(PageClassificationIngestionService::class)->store($payload);
        Livewire::test(DomainContacts::class, ['domainId' => $domain->id])
            ->assertSee('No published email found on the sampled pages.')->assertSee('Open contact form')->assertDontSeeHtml('type="radio"');
    }

    public function test_foreign_contact_cannot_be_selected_or_read(): void
    {
        $this->login();
        $domain = $this->domain();
        $other = Domain::factory()->create();
        $contact = app(ContactSelectionService::class)->addManual($other, 'info@other.test');
        foreach (['selectContact', 'showSources', 'excludeContact'] as $action) {
            try {
                Livewire::test(DomainContacts::class, ['domainId' => $domain->id])->call($action, $contact->id);
                $this->fail('A foreign contact must not be accessible.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(DomainContact::class, $exception->getModel());
            }
        }
        $this->assertNull($domain->fresh()->primary_contact_id);
    }

    public function test_guest_cannot_access_contacts_and_read_only_user_cannot_mutate_them(): void
    {
        $domain = $this->domain();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(DomainContacts::class, ['domainId' => $domain->id])->assertForbidden();
        $this->login();
        Gate::policy(Domain::class, ReadOnlyDomainPolicy::class);
        Livewire::test(DomainContacts::class, ['domainId' => $domain->id])
            ->assertDontSee('Add email manually')->set('newEmail', 'info@shop.test')->call('addEmail')->assertForbidden();
        $this->assertSame(0, $domain->contacts()->count());
    }

    public function test_untrusted_urls_and_malformed_contacts_are_not_rendered_or_imported(): void
    {
        $domain = $this->domain();
        $payload = $this->payload($domain, ['https://shop.test/' => ['info@shop.test']]);
        $contacts = &$payload['classification_metadata']['pages_scanned'][0]['contacts'];
        $contacts['emails'][0]['source_url'] = 'javascript:alert(1)';
        $contacts['emails'][] = ['value' => ['bad']];
        $contacts['forms'] = [['source_url' => 'javascript:alert(1)']];
        $contacts['contact_page_urls'] = ['https://user:password@shop.test/contact', 'https://other.test/contact'];
        $scan = app(PageClassificationIngestionService::class)->store($payload);
        $this->assertSame(0, $domain->contacts()->count());
        $result = app(ContactScanReader::class)->read($scan);
        $this->assertSame([], $result['links']);
        $this->assertSame('partial', $result['status']);
    }
}

class ReadOnlyDomainPolicy
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
