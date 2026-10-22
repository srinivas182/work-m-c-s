<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Billing\Models\CashPayment;
use Wurk\Billing\Models\CreditPack;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\CashPayments;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Security\Contracts\PlatformSettings;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** WURK staff: record cash paid at an office, and approve it with the code sent to approvers. */
final class CashPaymentsController
{
    public function __construct(private readonly CashPayments $cash, private readonly TenantDirectory $tenants) {}

    public function show(string $tenant, Catalog $catalog, PlatformSettings $settings, Request $request): Response
    {
        Gate::authorize('payments.record-cash');
        $business = $this->tenants->find($tenant) ?? abort(404);
        $sub = $catalog->subscriptionOf($tenant);
        $me = (string) $request->user()?->getAuthIdentifier();

        return Inertia::render('Billing::Admin/CashPayments', [
            'business' => ['id' => $tenant, 'name' => $business->displayName()],
            'subscription' => $sub === null ? null : ['package' => $sub['package'], 'period' => $sub['period'], 'period_cents' => $sub['period_cents'], 'status' => $sub['status']],
            'packs' => CreditPack::query()->where('active', true)->orderBy('credits')->get(['id', 'name', 'credits', 'price_cents'])->toArray(),
            'canRecord' => array_diff((array) $settings->get('cash_otp_recipients', []), [$me]) !== [],
            'cash' => CashPayment::query()->where('tenant_id', $tenant)->latest()->limit(20)->get()->map(function (CashPayment $c) {
                $p = Payment::query()->find($c->payment_id);

                return ['id' => $c->id, 'at' => $c->created_at?->toIso8601String(), 'payer' => $c->payer_name, 'receipt' => $c->receipt_number,
                    'status' => $c->status, 'total_cents' => $p?->total_cents, 'purpose' => $p?->purpose];
            })->all(),
        ]);
    }

    public function store(Request $request, string $tenant, Catalog $catalog): RedirectResponse
    {
        Gate::authorize('payments.record-cash');
        abort_if($this->tenants->find($tenant) === null, 404);
        $data = $request->validate([
            'purpose' => ['required', 'in:subscription,credits'],
            'pack_id' => ['required_if:purpose,credits', 'nullable', 'exists:credit_packs,id'],
            'payer_name' => ['required', 'string', 'max:120'],
            'receipt_number' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($data['purpose'] === 'subscription') {
            $sub = $catalog->subscriptionOf($tenant) ?? abort(422, 'This business has no package.');
            [$excl, $details] = [$sub['period_cents'], ['package' => $sub['package_key'], 'period' => $sub['period']]];
        } else {
            $pack = CreditPack::query()->whereKey((string) $data['pack_id'])->firstOrFail();
            [$excl, $details] = [$pack->price_cents, ['pack' => $pack->name, 'credits' => $pack->credits]];
        }

        $this->cash->record($tenant, $data['purpose'], $excl, $details, $data['payer_name'], $data['receipt_number'], $data['notes'] ?? null, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Recorded. An approver has been sent a code — enter it here to confirm the payment.');
    }

    public function approve(Request $request, string $tenant, string $cash): RedirectResponse
    {
        Gate::authorize('payments.record-cash');
        $record = CashPayment::query()->where('tenant_id', $tenant)->findOrFail($cash);
        $this->cash->approve($record, (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Cash payment confirmed. Receipts are on their way.');
    }

    public function resend(string $tenant, string $cash): RedirectResponse
    {
        Gate::authorize('payments.record-cash');
        $this->cash->resend(CashPayment::query()->where('tenant_id', $tenant)->findOrFail($cash));

        return back()->with('status', 'A new code was sent to the approvers.');
    }

    public function cancel(Request $request, string $tenant, string $cash): RedirectResponse
    {
        Gate::authorize('payments.record-cash');
        $this->cash->cancel(CashPayment::query()->where('tenant_id', $tenant)->findOrFail($cash), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', 'Cash payment cancelled.');
    }
}
