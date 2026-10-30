<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/** Leads for follow-up (forms, chats and quote requests open them). */
interface LeadBook
{
    /** Open a lead for a contact in the business's first pipeline, at its first stage; returns the lead's id. */
    public function open(string $contactId, string $title, string $source, ?int $valueCents = null, ?string $branchId = null, ?string $note = null): string;
}
