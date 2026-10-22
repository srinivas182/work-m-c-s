<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Wurk\Billing\Gateways\DebitOrderProvider;
use Wurk\Billing\Gateways\FakeDebitOrderProvider;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\Mandate;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\Mandates;
use Wurk\Billing\Support\SaBanking;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;

final class DebitOrderController
{
    public function __construct(private readonly Mandates $mandates, private readonly TenantContext $context) {}

    /** Owners: set up a DebiCheck debit order. */
    public function store(Request $request, Catalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'holder_name' => ['required', 'string', 'max:120'],
            'id_number' => ['required', 'string'],
            'bank' => ['required', Rule::in(array_keys(SaBanking::BANKS))],
            'branch_code' => ['required', 'regex:/^\d{6}$/'],
            'account_number' => ['required', 'regex:/^\d{6,13}$/'],
            'account_type' => ['required', Rule::in(SaBanking::ACCOUNT_TYPES)],
            'collection_day' => ['required', 'integer', 'between:1,28'],
        ], ['branch_code.regex' => 'Branch codes have 6 digits.', 'account_number.regex' => 'Account numbers have 6 to 13 digits.']);

        $sub = $catalog->subscriptionOf($this->context->id()) ?? abort(404);
        // DebiCheck mandates carry a maximum; allow 50% headroom for VAT and price changes.
        $max = (int) ($sub['period_cents'] * 1.5);
        $this->mandates->create($this->context->id(), [
            'holder_name' => (string) $data['holder_name'], 'id_number' => preg_replace('/\D/', '', (string) $data['id_number']) ?? '',
            'bank' => (string) $data['bank'], 'branch_code' => (string) $data['branch_code'], 'account_number' => (string) $data['account_number'],
            'account_type' => (string) $data['account_type'], 'collection_day' => (int) $data['collection_day'],
        ], $max);

        return back()->with('status', 'Your bank will ask you to approve the debit order — check your banking app or SMS.');
    }

    /** Switch back to card collections. */
    public function useCard(): RedirectResponse
    {
        BillingAccount::query()->whereKey($this->context->id())->update(['collection_method' => 'card']);

        return back()->with('status', 'Renewals will be charged to your card.');
    }

    /** Development only, stand-in provider: act as the bank. */
    public function simulate(Request $request, DebitOrderProvider $provider): RedirectResponse
    {
        abort_unless($provider instanceof FakeDebitOrderProvider && $provider->available(), 404);
        $type = (string) $request->validate(['type' => ['required', 'in:mandate.approved,mandate.rejected,collection.succeeded,collection.failed']])['type'];

        $mandate = Mandate::query()->where('tenant_id', $this->context->id())->latest()->firstOrFail();
        $payment = Payment::query()->where('tenant_id', $this->context->id())->where('gateway', 'debicheck')->where('status', 'pending')->latest()->first();
        $this->mandates->handle(['id' => 'sim:'.uniqid(), 'type' => $type, 'mandate' => $mandate->provider_mandate_id,
            'reference' => $payment?->reference, 'amount' => $payment?->total_cents, 'message' => 'Simulated by the bank']);

        return back()->with('status', 'Simulated: '.$type);
    }

    /** Provider events (signed). Each event is handled once. */
    public function webhook(Request $request, DebitOrderProvider $provider): Response
    {
        $event = $provider->parseEvent($request->getContent(), array_map(fn ($v) => (string) ($v[0] ?? ''), $request->headers->all()));
        if ($event === null) {
            return new Response('', 400);
        }

        if (DB::table('gateway_events')->insertOrIgnore(['id' => $event['id'], 'gateway' => $provider->name(), 'type' => $event['type'], 'received_at' => now()]) === 1) {
            $this->mandates->handle($event);
        }

        return new Response('', 200);
    }
}
