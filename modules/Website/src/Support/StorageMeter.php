<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Contracts\UsageMeter;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

final readonly class StorageMeter implements UsageMeter
{
    public function __construct(private Entitlements $entitlements, private TenantContext $context, private TenantDirectory $tenants) {}

    public function measure(string $tenantId): array
    {
        $tenant = $this->tenants->find($tenantId);
        $bytes = $tenant === null ? 0 : (int) $this->context->run($tenant, fn () => app(SiteAssets::class)->usedBytes());

        return ['label' => 'Storage', 'used' => round($bytes / 1048576, 1), 'included' => ($this->entitlements->limits($tenantId)['storage_mb'] ?? null) ?: null, 'unit' => 'MB'];
    }
}
