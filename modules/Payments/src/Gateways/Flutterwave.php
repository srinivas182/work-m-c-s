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
 * Flutterwave (Sprint 22.4), WURK's second gateway for its own billing: cards, and mobile money and bank transfers
 * outside South Africa, on one hosted checkout. Webhooks carry the secret hash WURK set in Flutterwave's dashboard;
 * a payment counts only once the transaction is verified with Flutterwave.
 */
final class Flutterwave
{
    public const API = 'https://api.flutterwave.com/v3';

    private static function http(PaymentAccount|PlatformPaymentAccount $a): PendingRequest
    {
        return Http::timeout(20)->acceptJson()->withToken((string) ($a->credentials['secret_key'] ?? ''));
    }

    /**
     * @param  array{name: string, email?: string|null}  $customer
     * @param  array{return: string, cancel: string, notify: string}  $urls
     * @return array{0: string, 1: string}|null [checkout page, reference]
     */
    public static function start(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, array $customer, array $urls): ?array
    {
        try {
            $r = self::http($a)->post(self::API.'/payments', [
                'tx_ref' => $attempt->reference, 'amount' => number_format($attempt->amount_cents / 100, 2, '.', ''), 'currency' => 'ZAR',
                'redirect_url' => $urls['return'],
                'customer' => ['email' => filter_var($customer['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'billing+'.strtolower(substr($attempt->reference, -12)).'@customers.wurk.co.za', 'name' => mb_substr($customer['name'], 0, 80)],
                'customizations' => ['title' => 'WURK'],
            ]);
        } catch (Throwable) {
            return null;
        }
        $url = $r->json('data.link');

        return $r->successful() && $r->json('status') === 'success' && is_string($url) && str_starts_with($url, 'https://') ? [$url, $attempt->reference] : null;
    }

    /** @return array{paid: bool, amount_cents: int, id: string|null} */
    public static function verify(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt): array
    {
        try {
            $r = self::http($a)->get(self::API.'/transactions/verify_by_reference', ['tx_ref' => $attempt->reference]);
        } catch (Throwable) {
            return ['paid' => false, 'amount_cents' => 0, 'id' => null];
        }
        $paid = $r->successful() && $r->json('data.status') === 'successful' && $r->json('data.tx_ref') === $attempt->reference && strtoupper((string) $r->json('data.currency')) === 'ZAR';

        return ['paid' => $paid, 'amount_cents' => $paid ? (int) round((float) $r->json('data.amount') * 100) : 0, 'id' => $paid ? (string) $r->json('data.id') : null];
    }

    public static function refund(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, int $cents): bool
    {
        if ($attempt->provider_id === null) {
            return false;
        }
        try {
            return self::http($a)->post(self::API.'/transactions/'.rawurlencode($attempt->provider_id).'/refund', ['amount' => number_format($cents / 100, 2, '.', '')])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A webhook carrying WURK's secret hash (verif-hash): the reference of a completed charge, or null. It is still
     * verified with Flutterwave before it counts.
     *
     * @param  array<string, string>  $headers
     */
    public static function notification(PaymentAccount|PlatformPaymentAccount $a, string $body, array $headers): ?string
    {
        $hash = (string) ($a->credentials['secret_hash'] ?? '');
        if ($hash === '' || ! hash_equals($hash, (string) ($headers['verif-hash'] ?? ''))) {
            return null;
        }
        $event = json_decode($body, true);

        return is_array($event) && ($event['event'] ?? '') === 'charge.completed' && is_string($event['data']['tx_ref'] ?? null) ? $event['data']['tx_ref'] : null;
    }

    /** Whether the key works. */
    public static function check(PaymentAccount|PlatformPaymentAccount $a): bool
    {
        try {
            return self::http($a)->get(self::API.'/balances')->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
