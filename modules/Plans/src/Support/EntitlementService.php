<?php

declare(strict_types=1);

namespace Wurk\Plans\Support;

use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Models\EntitlementOverride;
use Wurk\Plans\Models\TenantSubscription;

final class EntitlementService implements Entitlements
{
    /** @var array<string, array{modules: list<string>, limits: array<string, mixed>|null}> */
    private array $memo = [];

    public function entitled(string $tenantId, string $moduleKey): bool
    {
        return in_array($moduleKey, $this->modules($tenantId), true);
    }

    public function modules(string $tenantId): array
    {
        return $this->load($tenantId)['modules'];
    }

    public function limits(string $tenantId): ?array
    {
        /** @var array{storage_mb: int, staff_limit: int, multi_branch: bool, max_branches: int, custom_domain: bool, monthly_credits: int, referral_max_levels: int, referral_max_commission_bp: int}|null */
        return $this->load($tenantId)['limits'];
    }

    public function subscribedSince(): array
    {
        return TenantSubscription::query()->get(['tenant_id', 'started_at'])
            ->mapWithKeys(fn (TenantSubscription $s) => [$s->tenant_id => $s->started_at->toIso8601String()])->all();
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /** @return array{modules: list<string>, limits: array<string, mixed>|null} */
    private function load(string $tenantId): array
    {
        if (isset($this->memo[$tenantId])) {
            return $this->memo[$tenantId];
        }

        $version = TenantSubscription::query()->with('version')->find($tenantId)?->version;
        $modules = $version === null ? [] : $version->modules;

        foreach (EntitlementOverride::query()->where('tenant_id', $tenantId)->get() as $override) {
            $modules = $override->granted
                ? array_values(array_unique([...$modules, $override->module_key]))
                : array_values(array_diff($modules, [$override->module_key]));
        }

        $limits = $version === null ? null : [
            'storage_mb' => $version->storage_mb,
            'staff_limit' => $version->staff_limit,
            'multi_branch' => $version->multi_branch || in_array('multi-branch', $modules, true),
            'max_branches' => $version->max_branches,
            'custom_domain' => $version->custom_domain,
            'monthly_credits' => $version->monthly_credits,
            'referral_max_levels' => $version->referral_max_levels,
            'referral_max_commission_bp' => $version->referral_max_commission_bp,
        ];

        return $this->memo[$tenantId] = ['modules' => array_values($modules), 'limits' => $limits];
    }
}
