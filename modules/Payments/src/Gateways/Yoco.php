<?php

declare(strict_types=1);

namespace Wurk\Payments\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Models\PlatformPaymentAccount;
use Wurk\Payments\Models\PlatformPaymentAttempt;

/**
 * Yoco Checkout (the business's own account): a hosted checkout per payment; webhooks signed with a
 * secret Yoco issues when we register them; the checkout's status is always read back from Yoco.
 */
final class Yoco
{
    public const API = 'https://payments.yoco.com/api';

    private static function http(PaymentAccount|PlatformPaymentAccount $a): PendingRequest
    {
        return Http::timeout(20)->acceptJson()->withToken((string) ($a->credentials['secret_key'] ?? ''));
    }

    /**
     * Create the checkout; returns Yoco's page for the customer, and Yoco's checkout id.
     *
     * @param  array{return: string, cancel: string, notify: string}  $urls
     * @return array{0: string, 1: string}|null
     */
    public static function start(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, array $urls): ?array
    {
        try {
            $r = self::http($a)->withHeaders(['Idempotency-Key' => $attempt->reference])->post(self::API.'/checkouts', [
                'amount' => $attempt->amount_cents, 'currency' => 'ZAR',
                'successUrl' => $urls['return'], 'cancelUrl' => $urls['cancel'], 'failureUrl' => $urls['cancel'],
                'metadata' => ['reference' => $attempt->reference],
            ]);
        } catch (Throwable) {
            return null;
        }

        return $r->successful() && $r->json('redirectUrl') ? [(string) $r->json('redirectUrl'), (string) $r->json('id')] : null;
    }

    /** @return array{paid: bool, amount_cents: int} */
    public static function verify(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt): array
    {
        try {
            $r = self::http($a)->get(self::API.'/checkouts/'.rawurlencode((string) $attempt->provider_id));
        } catch (Throwable) {
            return ['paid' => false, 'amount_cents' => 0];
        }

        return ['paid' => $r->successful() && $r->json('status') === 'completed', 'amount_cents' => (int) $r->json('amount', 0)];
    }

    public static function refund(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, int $cents): bool
    {
        try {
            return self::http($a)->withHeaders(['Idempotency-Key' => 'refund-'.$attempt->reference.'-'.$cents])
                ->post(self::API.'/checkouts/'.rawurlencode((string) $attempt->provider_id).'/refund', ['amount' => $cents])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Register our notification address with Yoco; returns [webhook id, signing secret], or null if the
     * key is wrong (this doubles as the connection test).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function register(PaymentAccount|PlatformPaymentAccount $a, string $url): ?array
    {
        try {
            $r = self::http($a)->post(self::API.'/webhooks', ['name' => 'WURK', 'url' => $url]);
        } catch (Throwable) {
            return null;
        }

        return $r->successful() && $r->json('secret') ? [(string) $r->json('id'), (string) $r->json('secret')] : null;
    }

    /**
     * A signed webhook from Yoco (within 3 minutes); the checkout id it's about.
     *
     * @param  array<string, string>  $headers
     */
    public static function notification(PaymentAccount|PlatformPaymentAccount $a, string $body, array $headers): ?string
    {
        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        $secret = (string) $a->webhook_secret;
        if ($id === '' || $timestamp === '' || $secret === '' || abs(now()->getTimestamp() - (int) $timestamp) > 180) {
            return null;
        }
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", (string) base64_decode(substr($secret, strlen('whsec_')), true), true));
        $ok = false;
        foreach (explode(' ', $headers['webhook-signature'] ?? '') as $candidate) {
            $ok = $ok || hash_equals($expected, (string) (explode(',', $candidate, 2)[1] ?? ''));
        }
        $data = (array) json_decode($body, true);

        return $ok && ($data['type'] ?? '') === 'payment.succeeded' ? (string) ($data['payload']['metadata']['checkoutId'] ?? '') ?: null : null;
    }
}
