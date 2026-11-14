<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

use DateTimeInterface;

/**
 * Consent-based support access (Sprint 16.4): a business owner lets one WURK staff member into the back office —
 * view-only, or full — for a set time. Access and Identity ask here; the Support module answers (nobody, by default).
 */
interface SupportSessions
{
    /** Never granted to WURK staff, whatever the owner chooses: money, who has access, security, health records — and deciding on their own access. */
    public const NEVER = ['billing.manage', 'payments.manage', 'team.manage', 'roles.manage', 'team-security.manage', 'records.view', 'records.manage', 'support.access'];

    /**
     * The access this person has to this business through a support session right now, or null.
     *
     * @return array{id: string, level: 'view'|'full', staff_name: string, business: string, ends_at: DateTimeInterface, ticket: int}|null
     */
    public function active(string $tenantId, string $identityId): ?array;
}
