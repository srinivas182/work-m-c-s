<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/**
 * Raised inside a business: modules pass their existing customers (bookings, class places, orders) to the
 * ContactBook. Safe to run again — each record links to one contact.
 */
final readonly class ContactsBackfillRequested
{
    public function __construct(public string $tenantId) {}
}
