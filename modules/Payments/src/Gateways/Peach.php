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
 * Peach Payments hosted checkout (the business's own entity). A short-lived token, then a checkout per
 * payment; notifications are signed with the business's secret, and the status is always read back.
 */
final class Peach
{
    private static function token(PaymentAccount|PlatformPaymentAccount $a): ?string
    {
        try {
            $r = Http::timeout(20)->acceptJson()->post((string) config('payments.peach.auth.'.$a->mode), [
                'clientId' => $a->credentials['client_id'] ?? '', 'clientSecret' => $a->credentials['client_secret'] ?? '', 'merchantId' => $a->credentials['merchant_id'] ?? '',
            ]);
        } catch (Throwable) {
            return null;
        }

        return $r->successful() ? ((string) $r->json('access_token') ?: null) : null;
    }

    private static function http(PaymentAccount|PlatformPaymentAccount $a, string $token): PendingRequest
    {
        return Http::timeout(20)->acceptJson()->withToken($token);
    }

    private static function base(PaymentAccount|PlatformPaymentAccount $a): string
    {
        return (string) config('payments.peach.checkout.'.$a->mode);
    }

    /**
     * @param  array{return: string, cancel: string, notify: string}  $urls
     * @return array{0: string, 1: string}|null [redirect, checkout id]
     */
    public static function start(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, array $urls): ?array
    {
        $token = self::token($a);
        if ($token === null) {
            return null;
        }
        try {
            $r = self::http($a, $token)->withHeaders(['Idempotency-Key' => $attempt->reference])->post(self::base($a).'/v2/checkout', [
                'authentication' => ['entityId' => $a->credentials['entity_id'] ?? ''],
                'amount' => round($attempt->amount_cents / 100, 2), 'currency' => 'ZAR', 'merchantTransactionId' => $attempt->reference,
                'nonce' => $attempt->reference, 'shopperResultUrl' => $urls['return'], 'cancelUrl' => $urls['cancel'], 'notificationUrl' => $urls['notify'],
            ]);
        } catch (Throwable) {
            return null;
        }

        return $r->successful() && $r->json('redirectUrl') ? [(string) $r->json('redirectUrl'), (string) $r->json('checkoutId')] : null;
    }

    /** @return array{paid: bool, amount_cents: int} */
    public static function verify(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt): array
    {
        $token = self::token($a);
        if ($token === null || $attempt->provider_id === null) {
            return ['paid' => false, 'amount_cents' => 0];
        }
        try {
            $r = self::http($a, $token)->get(self::base($a).'/v2/checkout/'.rawurlencode($attempt->provider_id).'/status', ['authentication.entityId' => $a->credentials['entity_id'] ?? '']);
        } catch (Throwable) {
            return ['paid' => false, 'amount_cents' => 0];
        }
        // Peach result codes 000.000.* and 000.100.1* mean success.
        $code = (string) $r->json('result.code', '');
        $paid = $r->successful() && preg_match('/^(000\.000\.|000\.100\.1)/', $code) === 1;

        return ['paid' => $paid, 'amount_cents' => $paid ? (int) round((float) $r->json('amount', 0) * 100) : 0];
    }

    public static function refund(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, int $cents): bool
    {
        $token = self::token($a);
        if ($token === null || $attempt->provider_id === null) {
            return false;
        }
        try {
            return self::http($a, $token)->withHeaders(['Idempotency-Key' => 'refund-'.$attempt->reference.'-'.$cents])
                ->post(self::base($a).'/v2/checkout/'.rawurlencode($attempt->provider_id).'/refund', ['authentication' => ['entityId' => $a->credentials['entity_id'] ?? ''], 'amount' => round($cents / 100, 2), 'currency' => 'ZAR'])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A signed notification: HMAC-SHA256 over the sorted fields (except the signature) with the secret.
     *
     * @param  array<string, string>  $posted
     */
    public static function notification(PaymentAccount|PlatformPaymentAccount $a, array $posted): ?string
    {
        $signature = (string) ($posted['signature'] ?? '');
        unset($posted['signature']);
        ksort($posted);
        $payload = implode('', array_map(fn ($k, $v) => $k.$v, array_keys($posted), $posted));
        $expected = hash_hmac('sha256', $payload, (string) ($a->credentials['secret_token'] ?? ''));

        return $signature !== '' && hash_equals($expected, $signature) ? (string) ($posted['merchantTransactionId'] ?? '') ?: null : null;
    }
}
