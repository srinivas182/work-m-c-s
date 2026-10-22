<?php

declare(strict_types=1);

namespace Wurk\Identity\Contracts;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Creating WURK IDs for newly verified people, giving them a business, and signing them in. */
interface Accounts
{
    /** The WURK ID for a verified contact: the existing one, or a new one. Marks the contact verified. */
    public function forVerifiedContact(string $contact, string $firstName, string $lastName): string;

    public function makeOwner(string $identityId, string $tenantId, string $displayName): void;

    /** Completes sign-in (authenticator challenge first if they have one) and continues to $intended. */
    public function signIn(Request $request, string $identityId, string $intended): RedirectResponse;
}
