<?php

declare(strict_types=1);

namespace Wurk\Plans\Contracts;

/** What a business may use: modules from its package plus WURK staff overrides, and its limits. */
interface Entitlements
{
    public function entitled(string $tenantId, string $moduleKey): bool;

    /** @return list<string> entitlement keys the business can use */
    public function modules(string $tenantId): array;

    /**
     * @return array{storage_mb: int, staff_limit: int, multi_branch: bool, max_branches: int, custom_domain: bool, monthly_credits: int, referral_max_levels: int, referral_max_commission_bp: int}|null
     */
    public function limits(string $tenantId): ?array;

    public function flush(): void;
}
