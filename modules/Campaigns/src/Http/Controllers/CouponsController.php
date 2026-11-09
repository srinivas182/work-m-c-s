<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Coupons (Sprint 15.3b). */
final class CouponsController
{
    public function __construct(private TenantContext $context) {}

    public function index(): Response
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $use = $db->table('coupon_redemptions')->where('tenant_id', $t)->whereNull('released_at')->selectRaw('coupon_id, count(*) as uses, sum(discount_cents) as given')->groupBy('coupon_id')->get()->keyBy('coupon_id');

        return Inertia::render('Campaigns::Coupons', [
            'coupons' => $db->table('coupons')->where('tenant_id', $t)->orderByDesc('created_at')->get()->map(fn ($c) => [
                'id' => $c->id, 'code' => $c->code, 'kind' => $c->kind, 'value' => (int) $c->value, 'applies_to' => $c->applies_to, 'min_spend_cents' => (int) $c->min_spend_cents,
                'ends_at' => $c->ends_at, 'max_uses' => $c->max_uses, 'once_per_customer' => (bool) $c->once_per_customer, 'active' => (bool) $c->active,
                'uses' => (int) ($use[$c->id]->uses ?? 0), 'given_cents' => (int) ($use[$c->id]->given ?? 0),
            ])->all(),
            'campaigns' => $db->table('campaigns')->where('tenant_id', $t)->orderByDesc('created_at')->limit(50)->get(['id', 'name'])->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
        ]);
    }

    public function store(Request $request, AuditLog $audit): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $t = $this->context->id();
        $d = $request->validate([
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique($this->context->connectionName().'.coupons', 'code')->where('tenant_id', $t)],
            'kind' => ['required', Rule::in(['amount', 'percent'])],
            'value' => ['required', 'numeric', 'gt:0', $request->input('kind') === 'percent' ? 'max:100' : 'max:100000'],
            'applies_to' => ['required', Rule::in(['shop', 'bookings', 'both'])],
            'min_spend' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'ends_on' => ['nullable', 'date', 'after_or_equal:today'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'once_per_customer' => ['boolean'],
            'campaign_id' => ['nullable', 'string', Rule::exists($this->context->connectionName().'.campaigns', 'id')->where('tenant_id', $t)],
        ], ['code.regex' => 'Use letters, numbers, - or _ only.', 'code.unique' => 'You already have that code.', 'value.max' => 'A percentage can’t be more than 100.']);
        $id = (string) Str::ulid();
        DB::connection($this->context->connectionName())->table('coupons')->insert(['id' => $id, 'tenant_id' => $t, 'code' => $d['code'], 'kind' => $d['kind'],
            'value' => $d['kind'] === 'percent' ? (int) round((float) $d['value'] * 100) : (int) round((float) $d['value'] * 100), // basis points, or cents
            'applies_to' => $d['applies_to'], 'min_spend_cents' => (int) round((float) ($d['min_spend'] ?? 0) * 100),
            'ends_at' => isset($d['ends_on']) ? CarbonImmutable::parse((string) $d['ends_on'], $this->context->current()->timezone())->endOfDay()->setTimezone((string) config('app.timezone')) : null,
            'max_uses' => $d['max_uses'] ?? null, 'once_per_customer' => (bool) ($d['once_per_customer'] ?? false), 'campaign_id' => $d['campaign_id'] ?? null,
            'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $audit->record('campaigns.coupon_created', 'coupon', $id, ['code' => $d['code']]);

        return back()->with('status', "Coupon {$d['code']} is ready.");
    }

    public function switchOff(string $coupon, AuditLog $audit): RedirectResponse
    {
        $n = DB::connection($this->context->connectionName())->table('coupons')->where('tenant_id', $this->context->id())->where('id', $coupon)->update(['active' => false, 'updated_at' => now()]);
        abort_if($n === 0, 404);
        $audit->record('campaigns.coupon_switched_off', 'coupon', $coupon, []);

        return back()->with('status', 'Switched off. Orders already placed keep their discount.');
    }
}
