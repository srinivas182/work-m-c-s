<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Illuminate\Support\Facades\URL;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Tenancy\Contracts\TenantContext;

/** Card and instant-EFT payments into the business's own account (PayFast, Yoco, Ozow, Peach). */
final readonly class Payments implements BusinessPayments
{
    public function __construct(private TenantContext $context, private AuditLog $audit, private GatewayRouter $router) {}

    public function available(): array
    {
        // Paid online (never manual EFT): enabled here, allowed by WURK, and with keys for the current mode.
        $platform = app(PlatformMethods::class);
        $ready = PaymentAccount::query()->where('active', true)->get()
            ->filter(fn (PaymentAccount $a) => $a->hasKeys($a->mode) && $platform->available($a->provider))->pluck('provider')->all();

        return array_values(array_filter(PlatformMethods::ONLINE, fn (string $p) => in_array($p, $ready, true)));
    }

    public function start(string $reference, int $amountCents, string $description, array $customer, array $urls, ?string $provider = null): ?string
    {
        $provider ??= $this->available()[0] ?? null;
        $account = $provider === null || ! in_array($provider, $this->available(), true) ? null : PaymentAccount::query()->where('provider', $provider)->first();
        if ($account === null || $amountCents < 100) {
            return null;
        }
        $attempt = PaymentAttempt::query()->updateOrCreate(['reference' => $reference], ['provider' => $account->provider, 'amount_cents' => $amountCents, 'status' => 'pending']);
        $handle = $this->context->current()->handle();
        $urls['notify'] = 'https://'.$handle.'.'.config('wurk.domains.root').'/payments/notify/'.$account->provider;

        return $this->router->start($account, $attempt, $description, $customer, $urls,
            fn () => URL::signedRoute('payments.public.pay', ['handle' => $handle, 'reference' => $reference], now()->addHour()));
    }

    public function verify(string $reference): array
    {
        $attempt = PaymentAttempt::query()->find($reference);
        $account = $attempt === null ? null : PaymentAccount::query()->where('provider', $attempt->provider)->first();

        return $attempt === null || $account === null ? ['paid' => false, 'amount_cents' => 0] : $this->router->verify($account, $attempt);
    }

    public function refund(string $reference, ?int $amountCents = null): bool
    {
        $attempt = PaymentAttempt::query()->find($reference);
        $account = $attempt === null ? null : PaymentAccount::query()->where('provider', $attempt->provider)->first();
        if ($attempt === null || $account === null || $attempt->status !== 'paid') {
            return false;
        }
        $cents = min($attempt->amount_cents, $amountCents ?? $attempt->amount_cents);
        $ok = $this->router->refund($account, $attempt, $cents);
        $this->audit->record($ok ? 'payments.refunded' : 'payments.refund_failed', 'payment', $reference, ['cents' => $cents, 'provider' => $attempt->provider]);

        return $ok;
    }

    /**
     * A provider's notification: if it checks out, tell the modules (who verify again before acting).
     *
     * @param  array<string, string>  $posted
     * @param  array<string, string>  $headers
     */
    public function notified(string $provider, string $body, array $posted, array $headers): bool
    {
        $account = PaymentAccount::query()->where('provider', $provider)->first();
        $attempt = $account === null ? null : $this->router->notified($account, $body, $posted, $headers,
            fn (string $ref) => PaymentAttempt::query()->find($ref), fn (string $id) => PaymentAttempt::query()->where('provider_id', $id)->first());
        if ($attempt === null) {
            return false;
        }
        event(new BusinessPaymentReceived($this->context->id(), $attempt->reference));

        return true;
    }

    public function eftInstructions(): ?string
    {
        $eft = PaymentAccount::query()->where('provider', 'eft')->where('active', true)->first();
        if ($eft === null || ! $eft->hasKeys($eft->mode) || ! app(PlatformMethods::class)->available('eft')) {
            return null;
        }

        return (string) ($eft->credentials['instructions'] ?? '') ?: null;
    }

    public function setUpEft(string $instructions): void
    {
        PaymentAccount::query()->updateOrCreate(['provider' => 'eft'], ['mode' => 'live', 'active' => true, 'live_credentials' => ['instructions' => $instructions], 'label' => 'Bank details saved']);
    }
}
