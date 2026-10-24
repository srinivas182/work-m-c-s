<?php

declare(strict_types=1);

namespace Wurk\Access\Support;

use Wurk\Access\Models\Member;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Contracts\UsageMeter;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

final readonly class StaffMeter implements UsageMeter
{
    public function __construct(private Entitlements $entitlements, private TenantContext $context, private TenantDirectory $tenants) {}

    public function measure(string $tenantId): array
    {
        $tenant = $this->tenants->find($tenantId);
        $count = $tenant === null ? 0 : (int) $this->context->run($tenant, fn () => Member::query()->count());

        return ['label' => 'Team members', 'used' => $count, 'included' => ($this->entitlements->limits($tenantId)['staff_limit'] ?? null) ?: null, 'unit' => ''];
    }
}
