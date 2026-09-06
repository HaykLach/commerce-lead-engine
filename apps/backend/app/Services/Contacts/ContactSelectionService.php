<?php

declare(strict_types=1);

namespace App\Services\Contacts;

use App\Models\Domain;
use App\Models\DomainContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactSelectionService
{
    public function __construct(private readonly ContactPurposeClassifier $purposes) {}

    public function selectable(DomainContact $contact): bool
    {
        return $contact->review_status !== 'excluded' && ! $this->purposes->blocked($contact->effectivePurpose());
    }

    // The caller must hold the domain row lock. Only this scan's contacts qualify.
    public function reconcileAutomatic(Domain $domain, array $observedIds, bool $complete): void
    {
        if ($domain->contact_selection_mode === 'manual') {
            return;
        }
        $candidates = $complete ? $domain->contacts()->whereIn('id', $observedIds)->get()
            ->filter(fn (DomainContact $contact): bool => $this->selectable($contact)
                && $this->purposes->automatic($contact->effectivePurpose())
                && ContactEvidence::sameDomain($contact->email, $domain->normalized_domain)) : collect();

        $domain->forceFill(['primary_contact_id' => $candidates->count() === 1 ? $candidates->first()->id : null])->save();
    }

    public function select(Domain $domain, ?int $contactId): void
    {
        DB::transaction(function () use ($domain, $contactId): void {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($contactId !== null) {
                $contact = $domain->contacts()->findOrFail($contactId);
                if (! $this->selectable($contact)) {
                    throw ValidationException::withMessages(['contact' => 'This contact is excluded. Review its purpose or restore it first.']);
                }
                $contact->update(['review_status' => 'reviewed']);
            }
            // Clearing is also a manual decision; a later scan must not undo it.
            $domain->forceFill(['primary_contact_id' => $contactId, 'contact_selection_mode' => 'manual'])->save();
        }, 3);
    }

    public function updatePurpose(Domain $domain, int $contactId, string $purpose): void
    {
        if (! array_key_exists($purpose, $this->purposes->options())) {
            throw ValidationException::withMessages(['contact' => 'Choose a valid contact purpose.']);
        }
        $this->change($domain, $contactId, function (DomainContact $contact) use ($purpose): void {
            $contact->purpose_override = $purpose;
            if ($contact->review_status !== 'excluded') {
                $contact->review_status = 'reviewed';
            }
        });
    }

    public function exclude(Domain $domain, int $contactId, bool $excluded): void
    {
        $this->change($domain, $contactId, function (DomainContact $contact) use ($excluded): void {
            $contact->review_status = $excluded ? 'excluded' : 'candidate';
        });
    }

    public function addManual(Domain $domain, string $email): DomainContact
    {
        $email = ContactEvidence::email($email);
        if ($email === null) {
            throw ValidationException::withMessages(['newEmail' => 'Enter a valid email address.']);
        }

        return DB::transaction(function () use ($domain, $email): DomainContact {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $contact = $domain->contacts()->firstOrCreate(['email' => $email], ['suggested_purpose' => $this->purposes->classify($email)]);
            // Do not silently restore a previously excluded address.
            $contact->update(['is_manual' => true]);

            return $contact;
        }, 3);
    }

    private function change(Domain $domain, int $contactId, callable $change): void
    {
        DB::transaction(function () use ($domain, $contactId, $change): void {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $contact = $domain->contacts()->findOrFail($contactId);
            $change($contact);
            $contact->save();
            if ((int) $domain->primary_contact_id === $contact->id && ! $this->selectable($contact)) {
                $domain->forceFill(['primary_contact_id' => null, 'contact_selection_mode' => 'manual'])->save();
            }
        }, 3);
    }
}
