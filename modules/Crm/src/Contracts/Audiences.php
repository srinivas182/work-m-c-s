<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/**
 * Who a marketing campaign may reach (Sprint 15.3a): only customers who agreed to marketing (POPIA), by segment.
 * Segments: all | recent (seen in the last 30 days) | inactive (not seen in 60+ days) | branch | contacts (a given list).
 */
interface Audiences
{
    /**
     * @param  list<string>|null  $contactIds  for the 'contacts' segment (e.g. top referrers)
     * @return list<array{id: string, name: string, email: string|null, phone: string|null}>
     */
    public function audience(string $segment, ?string $branchId = null, ?array $contactIds = null): array;

    /** The customer opted out (a campaign's link): consent withdrawn, logged without personal details. */
    public function withdrawConsent(string $contactId, string $how): void;

    public function hasConsent(string $contactId): bool;
}
