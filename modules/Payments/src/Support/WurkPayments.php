<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Illuminate\Support\Facades\URL;
use Wurk\Payments\Contracts\PlatformPaymentReceived;
use Wurk\Payments\Contracts\PlatformPayments;
use Wurk\Payments\Models\PlatformPaymentAccount;
use Wurk\Payments\Models\PlatformPaymentAttempt;

/** Payments to WURK through WURK's own accounts (central), using the same gateways as businesses. */
final readonly class WurkPayments implements PlatformPayments
{
    public function __construct(private GatewayRouter $router) {}

    public function available(): array
    {
        $ready = PlatformPaymentAccount::query()->where('active', true)->get()->filter(fn (PlatformPaymentAccount $a) => $a->hasKeys($a->mode))->pluck('provider')->all();

        return array_values(array_filter(PlatformMethods::ONLINE, fn (string $p) => in_array($p, $ready, true)));
    }

    public function eftInstructions(): ?string
    {
        $eft = PlatformPaymentAccount::query()->where('provider', 'eft')->where('active', true)->first();

        return $eft === null ? null : ($eft->credentials['instructions'] ?? null);
    }

    public function start(string $reference, int $amountCents, string $description, array $customer, array $urls, string $provider): ?string
    {
        $account = in_array($provider, $this->available(), true) ? PlatformPaymentAccount::query()->find($provider) : null;
        if ($account === null || $amountCents < 100) {
            return null;
        }
        $attempt = PlatformPaymentAttempt::query()->updateOrCreate(['reference' => $reference], ['provider' => $provider, 'amount_cents' => $amountCents, 'status' => 'pending']);
        $urls['notify'] = 'https://'.config('wurk.domains.app').'/payments/notify/'.$provider;

        return $this->router->start($account, $attempt, $description, $customer, $urls,
            fn () => URL::signedRoute('payments.wurk.pay', ['reference' => $reference], now()->addHour()));
    }

    public function verify(string $reference): array
    {
        $attempt = PlatformPaymentAttempt::query()->find($reference);
        $account = $attempt === null ? null : PlatformPaymentAccount::query()->find($attempt->provider);

        return $attempt === null || $account === null ? ['paid' => false, 'amount_cents' => 0] : $this->router->verify($account, $attempt);
    }

    /**
     * @param  array<string, string>  $posted
     * @param  array<string, string>  $headers
     */
    public function notified(string $provider, string $body, array $posted, array $headers): bool
    {
        $account = PlatformPaymentAccount::query()->find($provider);
        $attempt = $account === null ? null : $this->router->notified($account, $body, $posted, $headers,
            fn (string $ref) => PlatformPaymentAttempt::query()->find($ref), fn (string $id) => PlatformPaymentAttempt::query()->where('provider_id', $id)->first());
        if ($attempt === null) {
            return false;
        }
        event(new PlatformPaymentReceived($attempt->reference));

        return true;
    }
}
