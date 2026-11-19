<?php

declare(strict_types=1);

namespace Wurk\Loyalty\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Loyalty\Support\Loyalty;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Loyalty card (Sprint 26.5): the owner sets it up and sees how it's going. */
final class LoyaltyController
{
    public function __construct(private Loyalty $loyalty, private TenantContext $context) {}

    public function show(): Response
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();

        return Inertia::render('Loyalty::Settings', [
            'settings' => $this->loyalty->settings(),
            'stats' => ['cards' => $db->table('loyalty_cards')->where('tenant_id', $t)->count(), 'ready' => $db->table('loyalty_rewards')->where('tenant_id', $t)->where('status', 'ready')->count(),
                'used' => $db->table('loyalty_rewards')->where('tenant_id', $t)->where('status', 'used')->count()],
            'example' => Loyalty::line(max(0, $this->loyalty->settings()['stamps'] - 2), $this->loyalty->settings()['stamps'], 0, $this->loyalty->settings()['reward']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $d = $request->validate(['on' => ['required', 'boolean'], 'reward' => ['required', 'string', 'max:80'], 'kind' => ['required', 'in:percent,amount'], 'value' => ['required', 'numeric', 'min:0'],
            'max' => ['nullable', 'numeric', 'min:0'], 'stamps' => ['required', 'integer'], 'min_spend' => ['nullable', 'numeric', 'min:0'], 'counts' => ['array'], 'counts.*' => ['in:booking,order,sale']]);
        $this->loyalty->save(['on' => (bool) $d['on'], 'reward' => (string) $d['reward'], 'kind' => (string) $d['kind'],
            'value' => (int) round((float) $d['value'] * 100), 'max_cents' => isset($d['max']) && (float) $d['max'] > 0 ? (int) round((float) $d['max'] * 100) : null,
            'stamps' => (int) $d['stamps'], 'min_spend_cents' => (int) round((float) ($d['min_spend'] ?? 0) * 100), 'counts' => array_values($d['counts'] ?? [])],
            (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Saved.'));
    }
}
