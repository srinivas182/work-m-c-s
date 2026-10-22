<?php

declare(strict_types=1);

namespace Wurk\Plans\Support;

use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Events\SubscriptionChanged;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Models\TenantSubscription;

final readonly class EloquentCatalog implements Catalog
{
    public function __construct(private Entitlements $entitlements, private AuditLog $audit) {}

    public function onSale(): array
    {
        return array_values(Package::query()->with('currentVersion')
            ->where('status', 'active')->where('visibility', 'public')->whereNotNull('current_version_id')
            ->orderBy('display_order')->orderBy('name')->get()
            ->map(function (Package $p): array {
                $v = $p->currentVersion;

                return [
                    'id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'description' => $p->description, 'type' => $p->type,
                    'monthly_cents' => (int) $v?->monthly_price_cents, 'yearly_cents' => (int) $v?->yearly_price_cents,
                    'trial_days' => (int) $v?->trial_days, 'staff_limit' => (int) $v?->staff_limit,
                    'multi_branch' => (bool) $v?->multi_branch, 'max_branches' => (int) $v?->max_branches,
                    'custom_domain' => (bool) $v?->custom_domain, 'monthly_credits' => (int) $v?->monthly_credits,
                    'modules' => $v === null ? [] : $v->modules,
                ];
            })->all());
    }

    public function subscriptionOf(string $tenantId): ?array
    {
        $s = TenantSubscription::query()->with(['package', 'version'])->find($tenantId);
        if ($s === null || $s->version === null || $s->package === null) {
            return null;
        }

        return [
            'package' => $s->package->name,
            'package_key' => $s->package->key,
            'version' => $s->version->version,
            'status' => $s->status,
            'period' => $s->billing_period,
            'period_cents' => $s->billing_period === 'yearly' ? $s->version->yearly_price_cents : $s->version->monthly_price_cents,
            'monthly_credits' => $s->version->monthly_credits,
            'trial_ends_at' => $s->trial_ends_at?->toIso8601String(),
            'current_period_end' => $s->current_period_end?->toIso8601String(),
        ];
    }

    public function markPaid(string $tenantId, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): void
    {
        TenantSubscription::query()->whereKey($tenantId)->update([
            'status' => 'active', 'current_period_start' => $periodStart, 'current_period_end' => $periodEnd,
        ]);
        $this->entitlements->flush();
        $this->audit->record('subscription.paid', 'tenant', $tenantId, ['until' => $periodEnd->format(DATE_ATOM)], ['platform' => true]);
    }

    public function markPastDue(string $tenantId): void
    {
        TenantSubscription::query()->whereKey($tenantId)->where('status', 'active')->update(['status' => 'past_due']);
        $this->audit->record('subscription.past_due', 'tenant', $tenantId, [], ['platform' => true]);
    }

    public function dueForRenewal(\DateTimeInterface $before): array
    {
        return array_values(array_map('strval', TenantSubscription::query()->where('status', 'active')
            ->whereNotNull('current_period_end')->where('current_period_end', '<=', $before)->pluck('tenant_id')->all()));
    }

    public function subscribe(string $tenantId, string $packageId, string $period, bool $allowTrial): array
    {
        $package = Package::query()->with('currentVersion')->whereKey($packageId)
            ->where('status', 'active')->where('visibility', 'public')->first();
        $version = $package?->currentVersion;
        if ($package === null || $version === null || ! in_array($period, ['monthly', 'yearly'], true)) {
            throw ValidationException::withMessages(['package' => 'That package isn’t available. Choose another one.']);
        }

        $trial = $allowTrial && $version->trial_days > 0;
        $subscription = TenantSubscription::query()->create([
            'tenant_id' => $tenantId,
            'package_id' => $package->id,
            'package_version_id' => $version->id,
            'status' => $trial ? 'trial' : 'pending_payment',
            'billing_period' => $period,
            'started_at' => now(),
            'trial_ends_at' => $trial ? now()->addDays($version->trial_days) : null,
        ]);

        $this->entitlements->flush();
        // Credits only once the business is live: on trial now, otherwise when the first payment lands (Sprint 3.2).
        event(new SubscriptionChanged($tenantId, $package->key, true, $trial ? $version->monthly_credits : 0));
        $this->audit->record('subscription.started', 'tenant', $tenantId, ['package' => $package->key, 'version' => $version->version, 'period' => $period, 'status' => $subscription->status], ['platform' => true]);

        return ['status' => $subscription->status, 'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()];
    }
}
