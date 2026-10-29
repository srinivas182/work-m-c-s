<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/** The business's contacts: one per person, built from everything they do. */
interface ContactBook
{
    /**
     * Remember someone from a booking, order, class place, form reply or chat: matched by exact phone or email
     * (phones normalised), else a new contact. Returns the contact's id. Marketing consent is recorded only when given.
     */
    public function remember(string $name, ?string $phone, ?string $email, string $sourceType, string $sourceId, ?string $branchId, string $summary, ?string $consentSource = null): string;
}
