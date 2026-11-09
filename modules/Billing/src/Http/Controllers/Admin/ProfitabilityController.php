<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Metrics\Costs;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\SubscriptionHistory;

/** Admin → Profitability (Sprint 15.5a): what things cost WURK, and a pricing "what if". */
final class ProfitabilityController
{
    public const CHANNELS = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'Email'];

    public function show(Costs $costs, SubscriptionHistory $subscriptions, Catalog $catalog): Response
    {
        Gate::authorize('metrics.view');
        $now = collect($subscriptions->on(now()));
        $names = $subscriptions->packageNames();
        $paying = $now->filter(fn ($r) => $r['mrr_cents'] > 0)->groupBy('package_id');
        $all = collect($costs->all());

        return Inertia::render('Billing::Admin/Profitability', [
            'canEdit' => Gate::allows('costs.manage'),
            'channels' => self::CHANNELS,
            'provider' => collect(self::CHANNELS)->map(fn ($l, $ch) => $all->first(fn ($c) => $c['kind'] === 'provider' && $c['channel'] === $ch && $c['country'] === '')['cents'] ?? null)->all(),
            'running' => $all->first(fn ($c) => $c['kind'] === 'running' && $c['package_id'] === '')['cents'] ?? null,
            'runningByPackage' => $all->filter(fn ($c) => $c['kind'] === 'running' && $c['package_id'] !== '')->mapWithKeys(fn ($c) => [$c['package_id'] => $c['cents']])->all(),
            'packages' => collect($names)->map(fn ($name, $id) => ['id' => (string) $id, 'name' => $name, 'paying' => isset($paying[$id]) ? $paying[$id]->count() : 0,
                'mrr_cents' => isset($paying[$id]) ? (int) $paying[$id]->sum('mrr_cents') : 0, 'monthly_cents' => (int) ($catalog->priceOf((string) $id, 'monthly')['period_cents'] ?? 0)])->values()->all(),
        ]);
    }

    public function update(Request $request, Costs $costs, AuditLog $audit): RedirectResponse
    {
        Gate::authorize('costs.manage');
        $rand = ['nullable', 'numeric', 'min:0', 'max:1000000'];
        $d = $request->validate(['provider' => ['array'], 'provider.*' => $rand, 'running' => $rand, 'running_by_package' => ['array'], 'running_by_package.*' => $rand]);
        $cents = fn ($v) => $v === null || $v === '' ? null : (int) round((float) $v * 100);
        foreach (array_keys(self::CHANNELS) as $ch) {
            $costs->set('provider', $ch, '', '', $cents($d['provider'][$ch] ?? null), (string) Auth::id());
        }
        $costs->set('running', '', '', '', $cents($d['running'] ?? null), (string) Auth::id());
        foreach ((array) ($d['running_by_package'] ?? []) as $pkg => $v) {
            $costs->set('running', '', '', (string) $pkg, $cents($v), (string) Auth::id());
        }
        $audit->record('billing.costs_updated', 'cost_settings', 'all', []);

        return back()->with('status', 'Saved.');
    }
}
