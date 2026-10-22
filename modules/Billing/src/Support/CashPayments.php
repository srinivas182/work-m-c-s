<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Gateways\GatewayResult;
use Wurk\Billing\Mail\CashApprovalCodeMail;
use Wurk\Billing\Mail\CashReceiptMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CashPayment;
use Wurk\Billing\Models\Payment;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Security\Contracts\PlatformSettings;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Cash paid at a WURK office (PRD §4.5). One person records it; it only counts
 * once someone else confirms with the code sent to the approvers in Security
 * settings — the recorder never receives their own code.
 */
final readonly class CashPayments
{
    public const CODE_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private Payments $payments,
        private PlatformSettings $settings,
        private PeopleDirectory $people,
        private Messenger $messenger,
        private TenantDirectory $tenants,
        private AuditLog $audit,
    ) {}

    /** @param  array<string, mixed>  $details */
    public function record(string $tenantId, string $purpose, int $exclCents, array $details, string $payerName, string $receiptNumber, ?string $notes, string $recordedBy): CashPayment
    {
        $approvers = array_values(array_diff((array) $this->settings->get('cash_otp_recipients', []), [$recordedBy]));
        if ($approvers === []) {
            throw ValidationException::withMessages(['cash' => 'Cash payments need an approver other than you. Add one in Settings → Security.']);
        }

        $code = (string) random_int(100000, 999999);
        $cash = DB::transaction(function () use ($tenantId, $purpose, $exclCents, $details, $payerName, $receiptNumber, $notes, $recordedBy, $approvers, $code): CashPayment {
            $payment = $this->payments->create($tenantId, $purpose, $exclCents, $details, 'cash');

            return CashPayment::query()->create([
                'payment_id' => $payment->id, 'tenant_id' => $tenantId, 'recorded_by' => $recordedBy,
                'payer_name' => $payerName, 'receipt_number' => $receiptNumber, 'notes' => $notes,
                'approvers' => $approvers, 'code_hash' => self::hash($code), 'code_expires_at' => now()->addMinutes(self::CODE_MINUTES),
            ]);
        });

        $this->sendCode($cash, $code);
        $this->audit->record('cash.recorded', 'cash_payment', $cash->id, ['tenant_id' => $tenantId, 'payer' => $payerName, 'receipt' => $receiptNumber, 'approvers' => count($approvers)], ['platform' => true]);

        return $cash;
    }

    public function resend(CashPayment $cash): void
    {
        $this->assertOpen($cash);
        $code = (string) random_int(100000, 999999);
        $cash->update(['code_hash' => self::hash($code), 'code_expires_at' => now()->addMinutes(self::CODE_MINUTES), 'attempts' => 0]);
        $this->sendCode($cash, $code);
    }

    public function approve(CashPayment $cash, string $code, string $approvedBy): void
    {
        $this->assertOpen($cash);

        // Decide under a lock, but let a wrong guess COMMIT its attempt before reporting it:
        // throwing inside the transaction would roll the counter back and allow unlimited guesses.
        $outcome = DB::transaction(function () use ($cash, $code, $approvedBy): string {
            $locked = CashPayment::query()->whereKey($cash->id)->lockForUpdate()->firstOrFail();
            if ($locked->code_expires_at->isPast()) {
                return 'expired';
            }
            if ($locked->attempts >= self::MAX_ATTEMPTS) {
                return 'locked';
            }
            if (! hash_equals($locked->code_hash, self::hash(trim($code)))) {
                $locked->increment('attempts');

                return 'wrong';
            }

            $locked->update(['status' => 'approved', 'approved_by' => $approvedBy, 'approved_at' => now()]);

            return 'approved';
        });

        match ($outcome) {
            'expired' => throw ValidationException::withMessages(['code' => 'That code has expired. Send a new one.']),
            'locked' => throw ValidationException::withMessages(['code' => 'Too many wrong codes. Send a new one.']),
            'wrong' => throw ValidationException::withMessages(['code' => 'That code isn’t right.']),
            default => null,
        };

        $payment = $cash->payment_id;
        $record = Payment::query()->findOrFail($payment);
        $this->payments->settle($record->reference, new GatewayResult('succeeded', $record->total_cents, $record->currency, 'cash:'.$cash->id));
        $this->audit->record('cash.approved', 'cash_payment', $cash->id, ['tenant_id' => $cash->tenant_id, 'total' => $record->total_cents / 100], ['platform' => true]);

        $receiptTo = array_values(array_filter([
            ...(array) $this->settings->get('cash_receipt_recipients', []),
            BillingAccount::query()->whereKey($cash->tenant_id)->value('billing_email'),
        ]));
        foreach (array_unique($receiptTo) as $to) {
            Mail::to((string) $to)->send(new CashReceiptMail(
                business: $this->tenants->find($cash->tenant_id)?->displayName() ?? '',
                receiptNumber: $cash->receipt_number,
                payer: $cash->payer_name,
                totalCents: $record->total_cents,
                vatCents: $record->vat_cents,
                purpose: $record->purpose,
            ));
        }
    }

    public function cancel(CashPayment $cash, string $by): void
    {
        $this->assertOpen($cash);
        $cash->update(['status' => 'cancelled']);
        Payment::query()->whereKey($cash->payment_id)->where('status', 'pending')->update(['status' => 'failed', 'failure' => 'Cancelled before approval']);
        $this->audit->record('cash.cancelled', 'cash_payment', $cash->id, ['by' => $by], ['platform' => true]);
    }

    private function sendCode(CashPayment $cash, string $code): void
    {
        $business = $this->tenants->find($cash->tenant_id)?->displayName() ?? 'a business';
        $total = Payment::query()->whereKey($cash->payment_id)->value('total_cents');
        $amount = 'R'.number_format(((int) $total) / 100, 2, '.', ' ');

        foreach ($this->people->contacts($cash->approvers) as $person) {
            $text = "WURK cash approval: {$amount} from {$business} (receipt {$cash->receipt_number}). Code {$code}, valid ".self::CODE_MINUTES.' minutes. Only share it if you agree.';
            if ($person['phone'] !== null && $this->messenger->sms($person['phone'], $text, 'cash_approval')->ok) {
                continue;
            }
            if ($person['email'] !== null) {
                Mail::to($person['email'])->send(new CashApprovalCodeMail($business, $amount, $cash->receipt_number, $code));
            }
        }
    }

    private function assertOpen(CashPayment $cash): void
    {
        if ($cash->status !== 'awaiting_approval') {
            throw ValidationException::withMessages(['code' => 'This cash payment is already '.str_replace('_', ' ', $cash->status).'.']);
        }
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
