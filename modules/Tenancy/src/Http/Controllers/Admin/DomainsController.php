<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Contracts\CustomHostnames;
use Wurk\Tenancy\Domains\CustomDomainManager;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantDomain;

/** Admin → Domains (Sprint 17.3): every business's own domains, their state and what's wrong — and a re-check. */
final class DomainsController
{
    public function index(CustomHostnames $hostnames): Response
    {
        Gate::authorize('viewAny', Tenant::class);
        $rows = TenantDomain::query()->with('tenant')->where('type', TenantDomain::TYPE_CUSTOM)
            ->orderByRaw("case status when 'lapsed' then 0 when 'pending' then 1 else 2 end")->orderByDesc('created_at')->limit(500)->get();

        return Inertia::render('Tenancy::Admin/Domains', [
            'configured' => $hostnames->configured(),
            'target' => (string) config('wurk.custom_domains.target'),
            'counts' => TenantDomain::query()->where('type', TenantDomain::TYPE_CUSTOM)->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'domains' => $rows->map(fn (TenantDomain $d) => ['id' => $d->id, 'host' => $d->host, 'status' => $d->status, 'ssl' => $d->ssl_status, 'primary' => $d->is_primary,
                'problem' => $d->problem, 'checked_at' => $d->checked_at?->toIso8601String(), 'business' => $d->tenant?->name, 'business_id' => $d->tenant_id])->values()->all(),
        ]);
    }

    public function check(string $domain, CustomDomainManager $domains): RedirectResponse
    {
        Gate::authorize('viewAny', Tenant::class);
        $d = TenantDomain::query()->where('type', TenantDomain::TYPE_CUSTOM)->findOrFail($domain);

        return back()->with('status', __(':host: :status.', ['host' => $d->host, 'status' => $domains->refresh($d)]));
    }
}
