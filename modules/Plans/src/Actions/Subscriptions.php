<?php

declare(strict_types=1);

namespace Wurk\Plans\Actions;

use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Events\SubscriptionChanged;
use Wurk\Plans\Models\EntitlementOverride;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Plans\Support\ModuleCatalogue;

/** Which package a business is on, and per-business module overrides. Billing arrives in Sprint 3. */
final readonly class Subscriptions
{
    public function __construct(private Entitlements $entitlements, private AuditLog $audit, private ModuleCatalogue $catalogue) {}

    public function assign(string $tenantId, Package $package): TenantSubscription
    {
        $version = $package->currentVersion;
        if ($version === null || $package->status !== 'active') {
            throw ValidationException::withMessages(['package' => 'That package is not available.']);
        }

        $before = TenantSubscription::query()->find($tenantId);
        $subscription = TenantSubscription::query()->updateOrCreate(['tenant_id' => $tenantId], [
            'package_id' => $package->id,
            'package_version_id' => $version->id,
            'status' => $version->trial_days > 0 && $before === null ? 'trial' : 'active',
            'started_at' => now(),
            'trial_ends_at' => $version->trial_days > 0 && $before === null ? now()->addDays($version->trial_days) : null,
        ]);

        $this->entitlements->flush();
        event(new SubscriptionChanged($tenantId, $package->key, $before === null, $version->monthly_credits));
        $this->audit->record('subscription.changed', 'tenant', $tenantId, [
            'from' => $before?->package_version_id, 'package' => $package->key, 'version' => $version->version,
        ], ['platform' => true]);

        return $subscription;
    }

    public function override(string $tenantId, string $moduleKey, ?bool $granted, string $reason, ?string $by): void
    {
        if (! in_array($moduleKey, $this->catalogue->keys(), true)) {
            throw ValidationException::withMessages(['module' => 'Unknown module.']);
        }

        if ($granted === null) {
            EntitlementOverride::query()->where('tenant_id', $tenantId)->where('module_key', $moduleKey)->delete();
        } else {
            EntitlementOverride::query()->where('tenant_id', $tenantId)->where('module_key', $moduleKey)->delete();
            EntitlementOverride::query()->create(['tenant_id' => $tenantId, 'module_key' => $moduleKey, 'granted' => $granted, 'reason' => $reason, 'created_by' => $by]);
        }

        $this->entitlements->flush();
        $this->audit->record('entitlement.overridden', 'tenant', $tenantId, ['module' => $moduleKey, 'granted' => $granted, 'reason' => $reason], ['platform' => true]);
    }
}
