<div class="domain-contacts">
    <style>
        .domain-contacts .contact-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; margin-bottom: 1rem; }
        .domain-contacts .contact-table-wrap { overflow-x: auto; margin: 1rem 0; }
        .domain-contacts table { width: 100%; border-collapse: collapse; text-align: left; }
        .domain-contacts th, .domain-contacts td { padding: .75rem; border-bottom: 1px solid #8884; vertical-align: top; }
        .domain-contacts .contact-note { font-size: .875rem; opacity: .8; margin-top: .35rem; }
        .domain-contacts .contact-links { display: flex; flex-wrap: wrap; gap: .75rem; margin: .75rem 0; }
        .domain-contacts .contact-source { padding: 1rem 0; border-bottom: 1px solid #8884; overflow-wrap: anywhere; }
        .domain-contacts .contact-email-input { flex: 1; min-width: 15rem; max-width: 30rem; }
    </style>

    <div class="contact-toolbar">
        <div>
            <strong>Primary email:</strong> {{ $domain->primaryContact?->email ?? 'Not selected' }}
            @if ($domain->primaryContact)
                <x-filament::badge color="gray">{{ $domain->contact_selection_mode === 'manual' ? 'Manually selected' : 'Automatically selected' }}</x-filament::badge>
            @endif
        </div>
        @if ($canEdit && $domain->primaryContact)
            <x-filament::button size="sm" color="gray" wire:click="selectContact(null)">Clear selection</x-filament::button>
        @endif
    </div>
    <p class="contact-note">Choose one primary email. Your manual selection, purpose changes, and exclusions are preserved after rescanning.</p>
    @if ($scan['scanned_at'])
        <p class="contact-note">Latest classification: {{ $scan['scanned_at']->toDateTimeString() }} UTC</p>
    @endif
    @if ($latestJob?->status === \App\Enums\CrawlJobStatus::Failed)
        <p role="status">The latest page scan failed. Previously collected contacts remain available.</p>
    @elseif ($scan['status'] === 'partial')
        <p role="status">Contact scanning was incomplete. Some pages or addresses could not be processed; automatic selection is paused.</p>
    @elseif ($scan['status'] === 'unavailable')
        <p role="status">No contact extraction results yet.</p>
    @endif
    @if ($scan['status'] !== 'unavailable' && $domain->contacts_classification_id !== $domain->latestPageClassification?->id)
        <p role="status">Contacts from the latest scan have not been imported.</p>
    @endif

    @if ($contactCount === 0 && $scan['status'] === 'collected')
        <p role="status">No published email found on the sampled pages.</p>
    @elseif ($contactCount > 0 && ! $domain->primaryContact)
        <p role="status">Needs contact review: select a suitable email or add one manually.</p>
    @endif
    @if ($contactCount > 0 && $scan['status'] === 'collected' && $scan['emails'] === [])
        <p class="contact-note">No email was observed in the latest scan. Previously collected contacts remain listed.</p>
    @endif

    @if ($contactCount > 0)
        <div class="contact-toolbar">
            <label for="contact-filter-{{ $domainId }}">Show contacts</label>
            <x-filament::input.wrapper>
                <x-filament::input.select :id="'contact-filter-'.$domainId" wire:model.live="filter">
                    <option value="all">All contacts</option>
                    <option value="needs_review">Needs review</option>
                    <option value="excluded">Manually excluded</option>
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div class="contact-table-wrap">
            <table>
                <caption class="sr-only">Published and manually added contact emails</caption>
                <thead><tr><th scope="col">Primary</th><th scope="col">Email</th><th scope="col">Purpose</th><th scope="col">Review</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                @forelse ($contacts as $contact)
                    <tr wire:key="contact-{{ $contact->id }}">
                        <td>
                            <input type="radio" name="primary-contact-{{ $domainId }}" value="{{ $contact->id }}"
                                aria-label="Select {{ $contact->email }} as primary contact"
                                wire:click="selectContact({{ $contact->id }})"
                                wire:loading.attr="disabled"
                                @checked((int) $domain->primary_contact_id === $contact->id)
                                @disabled(! $canEdit || ! $selection->selectable($contact))>
                        </td>
                        <td>
                            {{ $contact->email }}
                            <p class="contact-note">{{ $contact->last_seen_at ? 'Last observed '.$contact->last_seen_at->toDateTimeString().' UTC' : 'Manually added; not observed in a scan' }}</p>
                            @if (! \App\Services\Contacts\ContactEvidence::sameDomain($contact->email, $domain->normalized_domain))
                                <p class="contact-note">Email domain differs — review its source.</p>
                            @endif
                        </td>
                        <td>
                            <x-filament::input.wrapper>
                                <x-filament::input.select aria-label="Purpose for {{ $contact->email }}"
                                    wire:change="updatePurpose({{ $contact->id }}, $event.target.value)" :disabled="! $canEdit">
                                    @foreach ($purposes->options() as $value => $label)
                                        <option value="{{ $value }}" @selected($contact->effectivePurpose() === $value)>{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                            <p class="contact-note">{{ $contact->purpose_override === null ? 'Suggested purpose' : 'Manually corrected' }}</p>
                        </td>
                        <td>
                            <x-filament::badge color="gray">{{ $contact->review_status === 'excluded' ? 'Manually excluded' : ($purposes->blocked($contact->effectivePurpose()) ? 'Excluded by purpose' : ($contact->review_status === 'reviewed' ? 'Reviewed' : 'Needs review')) }}</x-filament::badge>
                        </td>
                        <td>
                            <div class="contact-links">
                                <x-filament::button size="sm" color="gray" wire:click="showSources({{ $contact->id }})">Sources ({{ $contact->sources_count }})</x-filament::button>
                                @if ($canEdit)
                                    @if ($contact->review_status === 'excluded')
                                        <x-filament::button size="sm" color="gray" wire:click="restoreContact({{ $contact->id }})">Restore</x-filament::button>
                                    @else
                                        <x-filament::button size="sm" color="gray" wire:click="excludeContact({{ $contact->id }})">Exclude</x-filament::button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5">No contacts match this filter.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $contacts->links() }}
    @endif
    @error('contact') <p role="alert">{{ $message }}</p> @enderror

    @if ($canEdit)
        <form wire:submit="addEmail" class="contact-toolbar">
            <div class="contact-email-input">
                <label for="new-contact-{{ $domainId }}">Add email manually</label>
                <x-filament::input.wrapper>
                    <x-filament::input :id="'new-contact-'.$domainId" type="email" wire:model="newEmail" maxlength="254" placeholder="Email address" required />
                </x-filament::input.wrapper>
                @error('newEmail') <p role="alert">{{ $message }}</p> @enderror
            </div>
            <x-filament::button type="submit" size="sm" wire:loading.attr="disabled">Add email</x-filament::button>
        </form>
    @endif

    @if ($scan['links'])
        <strong>Contact pages and forms</strong>
        <div class="contact-links">
            @foreach ($scan['links'] as $link)
                <x-filament::link :href="$link['url']" target="_blank" rel="noopener noreferrer">{{ $link['label'] === 'Contact form' ? 'Open contact form' : 'Open contact page' }}: {{ $link['url'] }}</x-filament::link>
            @endforeach
        </div>
    @endif

    <x-filament::modal :id="'contact-sources-'.$domainId" slide-over width="lg">
        <x-slot name="heading">Contact sources</x-slot>
        @if ($sourceContact)
            <strong>{{ $sourceContact->email }}</strong>
            @forelse ($sourceContact->sources as $source)
                <div class="contact-source">
                    <x-filament::link :href="$source->url" target="_blank" rel="noopener noreferrer">{{ $source->url }}</x-filament::link>
                    <p>Found in: {{ collect($source->methods)->map(fn ($method) => ['mailto' => 'Email link', 'html_text' => 'Page text', 'organization_jsonld' => 'Structured business information'][$method] ?? $method)->join(', ') ?: 'Not specified' }}</p>
                    <p>Original hint: {{ $source->original_hint ?? 'Not specified' }}</p>
                    <p>First observed: {{ $source->first_seen_at->toDateTimeString() }} UTC</p>
                    <p>Last observed: {{ $source->last_seen_at->toDateTimeString() }} UTC</p>
                </div>
            @empty
                <p>This address was added manually. No website source has been recorded.</p>
            @endforelse
        @endif
    </x-filament::modal>
</div>
