<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Gateways\DebitOrderProvider;
use Wurk\Billing\Gateways\GatewayResult;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\Mandate;
use Wurk\Billing\Models\Payment;

/** DebiCheck mandates and their events (approval, rejection, collection outcomes). */
final readonly class Mandates
{
    public function __construct(private DebitOrderProvider $provider, private Payments $payments, private AuditLog $audit) {}

    /** @param  array{holder_name: string, id_number: string, bank: string, branch_code: string, account_number: string, account_type: string, collection_day: int}  $data */
    public function create(string $tenantId, array $data, int $maxAmountCents): Mandate
    {
        if (! $this->provider->available()) {
            throw ValidationException::withMessages(['debit_order' => 'Debit orders open soon. Please pay by card for now.']);
        }
        if (! SaBanking::validIdNumber($data['id_number'])) {
            throw ValidationException::withMessages(['id_number' => 'That ID number isn’t valid. Check the 13 digits.']);
        }

        $mandate = Mandate::query()->create([
            'tenant_id' => $tenantId, 'provider' => $this->provider->name(), 'reference' => 'WURKDO'.Str::upper(Str::random(10)),
            'bank' => $data['bank'], 'account_type' => $data['account_type'], 'account_masked' => SaBanking::mask($data['account_number']),
            'holder_name' => $data['holder_name'], 'collection_day' => $data['collection_day'], 'max_amount_cents' => $maxAmountCents,
            'secret' => ['account_number' => $data['account_number'], 'branch_code' => $data['branch_code'], 'id_number' => $data['id_number']],
        ]);

        $result = $this->provider->createMandate([...$data, 'reference' => $mandate->reference, 'max_amount_cents' => $maxAmountCents]);
        $mandate->update($result['ok']
            ? ['provider_mandate_id' => $result['provider_id']]
            : ['status' => 'rejected', 'failure' => $result['message']]);
        $this->audit->record('mandate.requested', 'mandate', $mandate->id, ['tenant_id' => $tenantId, 'bank' => $data['bank'], 'account' => $mandate->account_masked, 'ok' => $result['ok']], ['platform' => true]);

        return $mandate;
    }

    /**
     * Starts a collection for a due period; the payment stays pending until the bank's outcome arrives.
     *
     * @param  array<string, mixed>  $details
     */
    public function collect(Mandate $mandate, string $tenantId, int $exclCents, array $details): Payment
    {
        $payment = $this->payments->create($tenantId, 'subscription', $exclCents, $details, 'debicheck');
        $payment->update(['details' => [...$payment->details, 'mandate_id' => $mandate->id]]);
        $result = $this->provider->collect((string) $mandate->provider_mandate_id, $payment->total_cents, $payment->reference);

        return $result->status === 'failed' ? $this->payments->settle($payment->reference, $result) : $payment;
    }

    /** @param  array{id: string, type: string, mandate: string|null, reference: string|null, amount: int|null, message: string|null}  $event */
    public function handle(array $event): void
    {
        if (str_starts_with($event['type'], 'mandate.')) {
            $mandate = Mandate::query()->where('provider_mandate_id', $event['mandate'])->first();
            if ($mandate === null || $mandate->status !== 'pending_authentication') {
                return;
            }

            $approved = $event['type'] === 'mandate.approved';
            $mandate->update(['status' => $approved ? 'active' : 'rejected', 'failure' => $approved ? null : ($event['message'] ?? 'Declined at the bank')]);
            if ($approved) {
                BillingAccount::query()->whereKey($mandate->tenant_id)->update(['collection_method' => 'debit_order', 'default_mandate_id' => $mandate->id]);
            }
            $this->audit->record($approved ? 'mandate.approved' : 'mandate.rejected', 'mandate', $mandate->id, ['tenant_id' => $mandate->tenant_id], ['platform' => true]);

            return;
        }

        $payment = $event['reference'] === null ? null : Payment::query()->where('reference', $event['reference'])->where('gateway', 'debicheck')->first();
        if ($payment === null) {
            return;
        }

        $this->payments->settle($payment->reference, $event['type'] === 'collection.succeeded'
            ? new GatewayResult('succeeded', $event['amount'], 'ZAR', $event['id'])
            : GatewayResult::failed($event['message'] ?? 'Debit order unpaid'));
    }

    public function active(string $tenantId): ?Mandate
    {
        $id = BillingAccount::query()->whereKey($tenantId)->where('collection_method', 'debit_order')->value('default_mandate_id');

        return $id === null ? null : Mandate::query()->whereKey((string) $id)->where('status', 'active')->first();
    }
}
