<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Illuminate\Support\Facades\URL;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Gateways\PayFast;
use Wurk\Payments\Gateways\Yoco;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Tenancy\Contracts\TenantContext;

/** Card payments into the business's own PayFast or Yoco account. */
final readonly class Payments implements BusinessPayments
{
    /** When both are connected, Yoco is offered first (a hosted page, no extra hop). */
    private const PREFERENCE = PlatformMethods::ONLINE;

    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function available(): array
    {
        // Paid online (never manual EFT): enabled here, allowed by WURK, and with keys for the current mode.
        $platform = app(PlatformMethods::class);
        $ready = PaymentAccount::query()->where('active', true)->get()
            ->filter(fn (PaymentAccount $a) => $a->hasKeys($a->mode) && $platform->available($a->provider))->pluck('provider')->all();

        return array_values(array_filter(self::PREFERENCE, fn (string $p) => in_array($p, $ready, true)));
    }

    public function start(string $reference, int $amountCents, string $description, array $customer, array $urls, ?string $provider = null): ?string
    {
        $provider ??= $this->available()[0] ?? null;
        $account = $provider === null ? null : PaymentAccount::query()->where('provider', $provider)->where('active', true)->first();
        if ($account === null || $amountCents < 100) {
            return null;
        }
        $attempt = PaymentAttempt::query()->updateOrCreate(['reference' => $reference], ['provider' => $account->provider, 'amount_cents' => $amountCents, 'status' => 'pending']);
        $urls['notify'] = 'https://'.$this->context->current()->handle().'.'.config('wurk.domains.root').'/payments/notify/'.$account->provider;
        if ($account->provider === 'yoco') {
            $started = Yoco::start($account, $attempt, $urls);
            if ($started === null) {
                return null;
            }
            $attempt->update(['provider_id' => $started[1]]);

            return $started[0];
        }
        $attempt->update(['form' => PayFast::form($account, $attempt, $description, $customer, $urls)]);

        return URL::signedRoute('payments.public.pay', ['handle' => $this->context->current()->handle(), 'reference' => $reference], now()->addHour());
    }

    public function verify(string $reference): array
    {
        $attempt = PaymentAttempt::query()->find($reference);
        $account = $attempt === null ? null : PaymentAccount::query()->where('provider', $attempt->provider)->first();
        if ($attempt === null || $account === null) {
            return ['paid' => false, 'amount_cents' => 0];
        }
        if ($attempt->provider === 'payfast') { // confirmed only by a notification that passed every check
            return ['paid' => $attempt->status === 'paid', 'amount_cents' => $attempt->status === 'paid' ? $attempt->amount_cents : 0];
        }
        $result = Yoco::verify($account, $attempt);
        if ($result['paid'] && $attempt->status === 'pending') {
            $attempt->update(['status' => 'paid', 'paid_at' => now()]);
        }

        return $result;
    }

    public function refund(string $reference, ?int $amountCents = null): bool
    {
        $attempt = PaymentAttempt::query()->find($reference);
        $account = $attempt === null ? null : PaymentAccount::query()->where('provider', $attempt->provider)->first();
        if ($attempt === null || $account === null || $attempt->status !== 'paid') {
            return false;
        }
        $cents = min($attempt->amount_cents, $amountCents ?? $attempt->amount_cents);
        $ok = $attempt->provider === 'yoco' ? Yoco::refund($account, $attempt, $cents) : PayFast::refund($account, $attempt, $cents);
        $this->audit->record($ok ? 'payments.refunded' : 'payments.refund_failed', 'payment', $reference, ['cents' => $cents, 'provider' => $attempt->provider]);
        if ($ok && $cents === $attempt->amount_cents) {
            $attempt->update(['status' => 'refunded']);
        }

        return $ok;
    }

    /**
     * A provider's notification (raw body and headers): if it checks out, mark the attempt paid and tell
     * the modules (who verify again before acting).
     *
     * @param  array<string, string>  $posted
     * @param  array<string, string>  $headers
     */
    public function notified(string $provider, string $body, array $posted, array $headers): bool
    {
        $account = PaymentAccount::query()->where('provider', $provider)->first();
        if ($account === null) {
            return false;
        }
        if ($provider === 'payfast') {
            $n = PayFast::notification($account, $posted);
            $attempt = $n === null ? null : PaymentAttempt::query()->find($n['reference']);
            if ($attempt === null || $n === null) {
                return false;
            }
            if ($attempt->status === 'pending') {
                $attempt->update(['status' => 'paid', 'paid_at' => now(), 'provider_id' => $n['provider_id']]);
            }
        } else {
            $checkout = Yoco::notification($account, $body, $headers);
            $attempt = $checkout === null ? null : PaymentAttempt::query()->where('provider_id', $checkout)->first();
            if ($attempt === null || ! $this->verify($attempt->reference)['paid']) {
                return false;
            }
        }
        event(new BusinessPaymentReceived($this->context->id(), $attempt->reference));

        return true;
    }
}
