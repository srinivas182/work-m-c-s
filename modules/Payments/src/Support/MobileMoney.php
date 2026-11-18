<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Gateways\MobileMoney\AirtelMoney;
use Wurk\Payments\Gateways\MobileMoney\MobileMoneyGateway;
use Wurk\Payments\Gateways\MobileMoney\MobileMoneyResult;
use Wurk\Payments\Gateways\MobileMoney\Mpesa;
use Wurk\Payments\Gateways\MobileMoney\MtnMomo;
use Wurk\Payments\Jobs\CheckMobileMoneyPayment;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Mobile money for a business's customers (Sprint 22.2): the customer gives their number, a prompt appears on their
 * phone, and they approve it with their PIN. The result is always read back from the provider — on the waiting page,
 * from the provider's callback, and by a job that keeps asking — and a prompt nobody answers expires.
 */
final readonly class MobileMoney
{
    /** How long a customer has to approve the prompt before it counts as timed out. */
    public const EXPIRES_SECONDS = 180;

    /** Prompts one payment may send. */
    public const MAX_PROMPTS = 5;

    /** Countries' dialling codes, for numbers typed the local way (0712…). */
    public const DIAL = ['KE' => '254', 'TZ' => '255', 'UG' => '256', 'GH' => '233', 'ZM' => '260', 'CM' => '237', 'CI' => '225', 'RW' => '250', 'MW' => '265'];

    /** Each country's currency, as customers see amounts. */
    public const CURRENCIES = ['KE' => 'KES', 'TZ' => 'TZS', 'UG' => 'UGX', 'GH' => 'GHS', 'ZM' => 'ZMW', 'CM' => 'XAF', 'CI' => 'XOF', 'RW' => 'RWF', 'MW' => 'MWK'];

    /** What the customer is told when a prompt fails. */
    public const MESSAGES = [
        'declined' => 'The payment was declined on your phone. You can try again.',
        'timeout' => 'Your phone didn’t answer in time. Check it has signal, then try again.',
        'funds' => 'There wasn’t enough money in your wallet. Top up, or try another number.',
        'pin' => 'The PIN was wrong. Try again and enter your mobile money PIN.',
        'other' => 'The payment didn’t go through. Try again, or choose another way to pay.',
    ];

    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public static function gateway(string $provider): MobileMoneyGateway
    {
        return app(match ($provider) {
            'mpesa' => Mpesa::class,
            'mtn_momo' => MtnMomo::class,
            default => AirtelMoney::class,
        });
    }

    /** The number in international digits (254712345678), or null if it can't be a mobile number in that country. */
    public static function msisdn(string $typed, string $country): ?string
    {
        $digits = preg_replace('/\D+/', '', $typed) ?? '';
        $dial = self::DIAL[strtoupper($country)] ?? null;
        if ($dial === null) {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        $local = match (true) {
            str_starts_with($digits, $dial) => substr($digits, strlen($dial)),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };

        return preg_match('/^[1-9]\d{7,9}$/', $local) === 1 ? $dial.$local : null;
    }

    private function account(PaymentAttempt $attempt): ?PaymentAccount
    {
        return PaymentAccount::query()->where('provider', $attempt->provider)
            ->when($attempt->branch_id === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $attempt->branch_id))->first();
    }

    /**
     * Send the prompt to the customer's phone. Returns null when it's on its way, or the reason it couldn't be sent
     * ("number" for a number that isn't one).
     */
    public function prompt(PaymentAttempt $attempt, string $typed): ?string
    {
        $country = $this->context->current()->countryCode();
        $msisdn = self::msisdn($typed, $country);
        if ($msisdn === null) {
            return 'number';
        }
        $account = $this->account($attempt);
        if ($account === null || ! in_array($attempt->status, ['pending', 'failed'], true) || (int) ($attempt->form['prompts'] ?? 0) >= self::MAX_PROMPTS) {
            return 'other'; // a customer's phone is never flooded with prompts
        }
        if ($attempt->status === 'pending' && $attempt->provider_id !== null && now()->getTimestamp() - (int) ($attempt->form['prompted_at'] ?? 0) < 30) {
            return null; // the last prompt is still on its way
        }
        if ($attempt->status === 'failed' && $attempt->provider_id !== null) {
            // Trying again: the earlier prompt may yet have been approved — never take the money twice.
            $earlier = self::gateway($attempt->provider)->status($account, $attempt, $country);
            if ($earlier->state === 'paid' && $earlier->amountCents >= $attempt->amount_cents) {
                $attempt->update(['status' => 'pending']);
                $this->check($attempt->refresh());

                return null;
            }
        }
        $handle = $this->context->current()->handle();
        $callback = 'https://'.$handle.'.'.config('wurk.domains.root').'/payments/notify/'.$attempt->provider.($attempt->branch_id ? '?branch='.$attempt->branch_id : '');
        // A new prompt for the same payment gets a fresh request id; the old one can no longer complete it.
        [$id, $failure] = self::gateway($attempt->provider)->request($account, $attempt, $msisdn, $country, $callback);
        $form = [...(array) $attempt->form, 'phone' => $msisdn, 'prompted_at' => now()->getTimestamp(), 'failure' => $failure, 'prompts' => (int) ($attempt->form['prompts'] ?? 0) + 1];
        $attempt->update(['provider_id' => $id, 'status' => $id === null ? 'failed' : 'pending', 'form' => $form]);
        $this->audit->record('payments.mobile_money_prompted', 'payment', $attempt->reference, ['provider' => $attempt->provider, 'sent' => $id !== null]);
        if ($id !== null) {
            CheckMobileMoneyPayment::dispatch($this->context->id(), $attempt->reference)->delay(now()->addSeconds(20));
        }

        return $failure;
    }

    /** Ask the provider how the prompt stands, and settle the payment: paid, failed (with the reason) or still waiting. */
    public function check(PaymentAttempt $attempt): MobileMoneyResult
    {
        if ($attempt->status === 'paid') {
            return MobileMoneyResult::paid($attempt->amount_cents);
        }
        if ($attempt->status !== 'pending' || $attempt->provider_id === null) {
            return MobileMoneyResult::failed((string) ($attempt->form['failure'] ?? 'other'));
        }
        $account = $this->account($attempt);
        $result = $account === null ? MobileMoneyResult::failed('other') : self::gateway($attempt->provider)->status($account, $attempt, $this->context->current()->countryCode());
        if ($result->state === 'paid' && $result->amountCents >= $attempt->amount_cents) {
            if (PaymentAttempt::query()->whereKey($attempt->reference)->where('status', 'pending')->update(['status' => 'paid', 'paid_at' => now()]) === 1) {
                event(new BusinessPaymentReceived($this->context->id(), $attempt->reference)); // once, whoever saw it first
            }

            return $result;
        }
        $expired = now()->getTimestamp() - (int) ($attempt->form['prompted_at'] ?? 0) > self::EXPIRES_SECONDS;
        if ($result->state === 'failed' || ($result->state === 'paid') || $expired) {
            $reason = $result->state === 'failed' ? (string) $result->reason : ($result->state === 'paid' ? 'other' : 'timeout');
            $attempt->update(['status' => 'failed', 'form' => [...(array) $attempt->form, 'failure' => $reason]]);

            return MobileMoneyResult::failed($reason);
        }

        return MobileMoneyResult::pending();
    }
}
