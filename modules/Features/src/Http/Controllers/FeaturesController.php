<?php

declare(strict_types=1);

namespace Wurk\Features\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;
use Wurk\Features\Support\FeatureFlags;
use Wurk\Tenancy\Contracts\TenantContext;

final class FeaturesController
{
    public function __construct(private readonly FeatureFlags $flags) {}

    /** WURK staff: rollout rules for every declared feature. */
    public function admin(): Response
    {
        Gate::authorize('features.manage');

        return Inertia::render('Features::Admin', [
            'features' => array_map(fn (array $f, string $key) => $f + ['key' => $key, 'rule' => $this->flags->rule($key), 'opt_ins' => $this->flags->optInCount($key)],
                $this->flags->declared(), array_keys($this->flags->declared())),
            'windowDays' => FeatureFlags::OPT_IN_WINDOW_DAYS,
        ]);
    }

    public function update(Request $request, string $feature): RedirectResponse
    {
        Gate::authorize('features.manage');
        abort_unless(isset($this->flags->declared()[$feature]), 404);
        $data = $request->validate([
            'mode' => ['required', Rule::in([FeatureFlags::OFF, FeatureFlags::OPT_IN, FeatureFlags::PERCENTAGE, FeatureFlags::ON])],
            'percentage' => ['nullable', 'integer', 'between:0,100'],
        ]);
        $this->flags->setRule($feature, $data['mode'], (int) ($data['percentage'] ?? 0), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Rollout updated.');
    }

    /** Business owners: optional new features they can switch on early. */
    public function business(TenantContext $context): Response
    {
        $optional = array_filter($this->flags->declared(), fn (string $key) => $this->flags->rule($key)['mode'] === FeatureFlags::OPT_IN, ARRAY_FILTER_USE_KEY);

        return Inertia::render('Features::Business', [
            'features' => array_map(fn (array $f, string $key) => $f + [
                'key' => $key,
                'on' => Feature::active($key),
                'default_on_at' => $this->flags->rule($key)['default_on_at'],
            ], $optional, array_keys($optional)),
        ]);
    }

    public function optIn(Request $request, TenantContext $context, string $feature): RedirectResponse
    {
        abort_unless(($this->flags->declared()[$feature] ?? null) !== null && $this->flags->rule($feature)['mode'] === FeatureFlags::OPT_IN, 404);
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $this->flags->setOptIn($context->id(), $feature, (bool) $data['on'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', $data['on'] ? 'Switched on for your business.' : 'Switched off for your business.');
    }
}
