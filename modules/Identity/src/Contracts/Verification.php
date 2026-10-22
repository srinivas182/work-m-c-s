<?php

declare(strict_types=1);

namespace Wurk\Identity\Contracts;

use Illuminate\Validation\ValidationException;

/** Proving someone controls an email address or mobile number, before they have a WURK ID. */
interface Verification
{
    /**
     * Sends a one-time code. Errors (bad address, too many requests) are
     * ValidationExceptions on the given field, ready to show in a form.
     *
     * @return array{type: string, value: string, masked: string}
     *
     * @throws ValidationException
     */
    public function send(string $contact, string $purpose, ?string $ip, string $field = 'contact'): array;

    public function confirm(string $contact, string $code, string $purpose): bool;

    /** Stable, non-reversible key for an address (e.g. "one trial per person"). */
    public function fingerprint(string $contact): string;
}
