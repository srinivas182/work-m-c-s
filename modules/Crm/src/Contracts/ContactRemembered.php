<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/**
 * A customer was remembered — created, or matched to an existing contact — from a booking, class place, order,
 * form, chat or sale. Raised inside the same transaction, so listeners never act on a contact that was rolled back.
 */
final readonly class ContactRemembered
{
    public function __construct(public string $contactId, public bool $created, public string $sourceType, public string $sourceId) {}
}
