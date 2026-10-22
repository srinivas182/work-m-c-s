<?php

declare(strict_types=1);

namespace Wurk\Plans\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Plans\Actions\Subscriptions;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Models\EntitlementOverride;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Plans\Support\ModuleCatalogue;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** A business's package and module overrides (WURK staff). */
final class BusinessPlanController
{
    public function __construct(private readonly Subscriptions $subscriptions, private readonly TenantDirectory $directory) {}

    public function show(string $tenant, Entitlements $entitlements, ModuleCatalogue $catalogue): Response
    {
        Gate::authorize('packages.view');
        $business = $this->directory->find($tenant) ?? abort(404);
        $subscription = TenantSubscription::query()->with(['package', 'version'])->find($tenant);

        return Inertia::render('Plans::Admin/BusinessPlan', [
            'business' => ['id' => $business->tenantId(), 'name' => $business->displayName()],
            'subscription' => $subscription === null ? null : [
                'package' => $subscription->package?->name, 'package_id' => $subscription->package_id,
                'version' => $subscription->version?->version, 'status' => $subscription->status,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'latest' => $subscription->package?->current_version_id === $subscription->package_version_id,
            ],
            'packages' => Package::query()->where('status', 'active')->orderBy('display_order')->get(['id', 'name'])->toArray(),
            'modules' => array_map(fn (array $m, string $key) => [
                'key' => $key, 'name' => $m['name'], 'installed' => $m['installed'],
                'enabled' => $entitlements->entitled($tenant, $key),
                'override' => EntitlementOverride::query()->where('tenant_id', $tenant)->where('module_key', $key)->first()?->only(['granted', 'reason']),
            ], $catalogue->all(), array_keys($catalogue->all())),
            'canManage' => Gate::allows('packages.manage'),
        ]);
    }

    public function assign(Request $request, string $tenant): RedirectResponse
    {
        Gate::authorize('packages.manage');
        abort_if($this->directory->find($tenant) === null, 404);
        $data = $request->validate(['package_id' => ['required', 'string', 'exists:packages,id']]);
        $this->subscriptions->assign($tenant, Package::query()->whereKey((string) $data['package_id'])->firstOrFail());

        return back()->with('status', 'Package changed.');
    }

    public function override(Request $request, string $tenant): RedirectResponse
    {
        Gate::authorize('packages.manage');
        abort_if($this->directory->find($tenant) === null, 404);
        $data = $request->validate([
            'module' => ['required', 'string'],
            'state' => ['required', 'in:grant,revoke,package'],
            'reason' => ['required_unless:state,package', 'nullable', 'string', 'min:5', 'max:500'],
        ]);
        $granted = ['grant' => true, 'revoke' => false, 'package' => null][$data['state']];
        $this->subscriptions->override($tenant, $data['module'], $granted, (string) ($data['reason'] ?? ''), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Module access updated.');
    }
}
