<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers\Admin;

use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\SiteLaunch;

/** WURK staff: time from creating a business to first publishing its site — the one-hour goal, measured. */
final class LaunchTimesController
{
    public function __invoke(TenantDirectory $tenants): Response
    {
        Gate::authorize('tenants.view');
        $launches = SiteLaunch::query()->orderByDesc('launched_at')->limit(200)->get();
        $minutes = $launches->pluck('minutes')->filter(fn ($m) => $m !== null)->sort()->values();
        $median = $minutes->isEmpty() ? null : (int) $minutes->median();

        return Inertia::render('Website::Admin/LaunchTimes', [
            'median' => $median,
            'withinHour' => $minutes->isEmpty() ? null : (int) round($minutes->filter(fn ($m) => $m <= 60)->count() / $minutes->count() * 100),
            'count' => $launches->count(),
            'launches' => $launches->map(fn (SiteLaunch $l) => [
                'business' => $tenants->find($l->tenant_id)?->displayName() ?? 'Unknown',
                'template' => $l->template_key,
                'minutes' => $l->minutes,
                'launched' => $l->launched_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
