<?php

declare(strict_types=1);

namespace Wurk\Identity\Contracts;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Creating WURK IDs for newly verified people, giving them a business, and signing them in. */
interface Accounts
{
    /** The WURK ID for a verified contact: the existing one, or a new one. Marks the contact verified. */
    public function forVerifiedContact(string $contact, string $firstName, string $lastName): string;

    public function makeOwner(string $identityId, string $tenantId, string $displayName): void;

    /** Completes sign-in (authenticator challenge first if they have one) and continues to $intended. */
    public function signIn(Request $request, string $identityId, string $intended): RedirectResponse;

    /** Whether an active WURK ID with this email or number has its own password. */
    public function hasPassword(string $identifier): bool;

    /**
     * The WURK ID whose password this is — throttled exactly like staff sign-in.
     *
     * @throws ValidationException too many attempts
     */
    public function checkPassword(string $identifier, string $password, ?string $ip): ?string;

    public function setPassword(string $identityId, string $password): void;

    /** @return list<mixed> WURK's password rules (for validating a new password). */
    public function passwordRules(): array;

    /** The active WURK ID with this email or number, if any — never creates one. */
    public function identityFor(string $identifier): ?string;
}
