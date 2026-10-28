<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Closure;
use Wurk\Payments\Gateways\Ozow;
use Wurk\Payments\Gateways\PayFast;
use Wurk\Payments\Gateways\Peach;
use Wurk\Payments\Gateways\Yoco;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Models\PlatformPaymentAccount;
use Wurk\Payments\Models\PlatformPaymentAttempt;

/**
 * Every provider decision in one place, for both a business's payments and WURK's own: start a payment,
 * verify it with the provider, refund it, and read a notification. Nothing counts until verified.
 */
final class GatewayRouter
{
    /**
     * @param  array{name: string, email?: string|null}  $customer
     * @param  array{return: string, cancel: string, notify: string}  $urls
     * @param  Closure(): string  $handOff  the business's or WURK's hand-off page for form-posting providers
     */
    public function start(PaymentAccount|PlatformPaymentAccount $account, PaymentAttempt|PlatformPaymentAttempt $attempt, string $description, array $customer, array $urls, Closure $handOff): ?string
    {
        if (in_array($account->provider, ['yoco', 'peach'], true)) { // hosted pages: redirect
            $started = $account->provider === 'yoco' ? Yoco::start($account, $attempt, $urls) : Peach::start($account, $attempt, $urls);
            if ($started === null) {
                return null;
            }
            $attempt->update(['provider_id' => $started[1]]);

            return $started[0];
        }
        // Form posts (PayFast, Ozow): a hand-off page sends the browser on with the signed fields.
        $attempt->update(['form' => $account->provider === 'ozow' ? Ozow::form($account, $attempt, $urls) : PayFast::form($account, $attempt, $description, $customer, $urls)]);

        return $handOff();
    }

    /** @return array{paid: bool, amount_cents: int} */
    public function verify(PaymentAccount|PlatformPaymentAccount $account, PaymentAttempt|PlatformPaymentAttempt $attempt): array
    {
        if ($attempt->provider === 'payfast') { // confirmed only by a notification that passed every check
            return ['paid' => $attempt->status === 'paid', 'amount_cents' => $attempt->status === 'paid' ? $attempt->amount_cents : 0];
        }
        $result = match ($attempt->provider) {
            'ozow' => Ozow::verify($account, $attempt),
            'peach' => Peach::verify($account, $attempt),
            default => Yoco::verify($account, $attempt),
        };
        if ($result['paid'] && $result['amount_cents'] >= $attempt->amount_cents && $attempt->status === 'pending') {
            $attempt->update(['status' => 'paid', 'paid_at' => now()]);
        }

        return $result;
    }

    public function refund(PaymentAccount|PlatformPaymentAccount $account, PaymentAttempt|PlatformPaymentAttempt $attempt, int $cents): bool
    {
        $ok = match ($attempt->provider) {
            'yoco' => Yoco::refund($account, $attempt, $cents),
            'peach' => Peach::refund($account, $attempt, $cents),
            'payfast' => PayFast::refund($account, $attempt, $cents),
            default => false, // Ozow: refunded in Ozow's portal, then recorded by the owner
        };
        if ($ok && $cents === $attempt->amount_cents) {
            $attempt->update(['status' => 'refunded']);
        }

        return $ok;
    }

    /**
     * A provider's notification: the attempt it confirms, once verified — or null.
     *
     * @param  array<string, string>  $posted
     * @param  array<string, string>  $headers
     * @param  Closure(string): (PaymentAttempt|PlatformPaymentAttempt|null)  $byReference
     * @param  Closure(string): (PaymentAttempt|PlatformPaymentAttempt|null)  $byProviderId
     */
    public function notified(PaymentAccount|PlatformPaymentAccount $account, string $body, array $posted, array $headers, Closure $byReference, Closure $byProviderId): PaymentAttempt|PlatformPaymentAttempt|null
    {
        switch ($account->provider) {
            case 'payfast':
                $attempt = $byReference((string) ($posted['m_payment_id'] ?? ''));
                $n = PayFast::notification($account, $posted, $attempt);
                if ($n === null || $attempt === null) {
                    return null;
                }
                if ($attempt->status === 'pending') {
                    $attempt->update(['status' => 'paid', 'paid_at' => now(), 'provider_id' => $n['provider_id']]);
                }

                return $attempt;
            case 'ozow':
                $n = Ozow::notification($account, $posted);
                $attempt = $n === null ? null : $byReference($n['reference']);
                if ($attempt === null || $n === null) {
                    return null;
                }
                $attempt->update(['provider_id' => $n['provider_id']]);
                break;
            case 'peach':
                $reference = Peach::notification($account, $posted);
                $attempt = $reference === null ? null : $byReference($reference);
                break;
            default:
                $checkout = Yoco::notification($account, $body, $headers);
                $attempt = $checkout === null ? null : $byProviderId($checkout);
        }
        if ($attempt === null) {
            return null;
        }
        $result = $this->verify($account, $attempt); // a notification alone confirms nothing

        return $result['paid'] && $result['amount_cents'] >= $attempt->amount_cents ? $attempt : null;
    }
}
