<?php

declare(strict_types=1);

namespace App\Services\Contacts;

class ContactPurposeClassifier
{
    public function classify(string $email): string
    {
        $local = explode('@', $email, 2)[0];
        foreach (config('contacts.purposes', []) as $purpose => $rule) {
            if (isset($rule['pattern']) && preg_match($rule['pattern'], $local) === 1) {
                return $purpose;
            }
        }

        return 'unknown';
    }

    public function options(): array
    {
        return array_map(fn (array $rule): string => $rule['label'], config('contacts.purposes', []));
    }

    public function blocked(string $purpose): bool
    {
        return (bool) config("contacts.purposes.{$purpose}.blocked", false);
    }

    public function automatic(string $purpose): bool
    {
        return ! $this->blocked($purpose) && (bool) config("contacts.purposes.{$purpose}.automatic", false);
    }
}
