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
 * Paystack (Sprint 22.1), on the business's own account or WURK's: a hosted checkout with the channels the owner chose.
 * In South Africa these are card, EFT, Capitec Pay and SnapScan (QR). Webhooks are signed with the secret key
 * (HMAC-SHA512 of the body); a payment counts only once the transaction is verified with Paystack. Mobile money is a
 * channel for accounts in Ghana, Kenya and Côte d'Ivoire, switched on with the country packs (Sprint 25).
 */
final class Paystack
{
    public const API = 'https://api.paystack.co';

    /** Paystack's channel names, and what owners see. */
    public const CHANNELS = ['card' => 'Card', 'eft' => 'EFT', 'capitec_pay' => 'Capitec Pay', 'qr' => 'SnapScan (QR code)', 'mobile_money' => 'Mobile money'];

    /** The channels Paystack offers an account registered in each country. */
    public const BY_COUNTRY = ['ZA' => ['card', 'eft', 'capitec_pay', 'qr'], 'GH' => ['card', 'mobile_money'], 'KE' => ['card', 'mobile_money'], 'CI' => ['card', 'mobile_money']];

    /** @return list<string> the channels for a country (South Africa's until the country packs) */
    public static function channelsFor(?string $country): array
    {
        return self::BY_COUNTRY[strtoupper((string) $country)] ?? self::BY_COUNTRY['ZA'];
    }

    private static function http(PaymentAccount|PlatformPaymentAccount $a): PendingRequest
    {
        return Http::timeout(20)->acceptJson()->withToken((string) ($a->credentials['secret_key'] ?? ''));
    }

    /**
     * Initialize the transaction; returns Paystack's checkout page and its access code.
     *
     * @param  array{name: string, email?: string|null}  $customer
     * @param  array{return: string, cancel: string, notify: string}  $urls
     * @return array{0: string, 1: string}|null
     */
    public static function start(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, array $customer, array $urls): ?array
    {
        // The owner's choice (a business account); otherwise every channel the Paystack account has switched on.
        $channels = $a instanceof PaymentAccount ? array_values(array_filter((array) ($a->options['channels'] ?? []), 'is_string')) : [];
        try {
            $r = self::http($a)->post(self::API.'/transaction/initialize', array_filter([
                // Paystack needs an email. A customer without one gets a stand-in address that receives nothing.
                'email' => filter_var($customer['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'pay+'.strtolower(substr($attempt->reference, -12)).'@customers.wurk.co.za',
                'amount' => $attempt->amount_cents, 'currency' => 'ZAR', 'reference' => $attempt->reference,
                'callback_url' => $urls['return'], 'channels' => $channels === [] ? null : $channels,
                'metadata' => ['cancel_action' => $urls['cancel'], 'custom_fields' => [['display_name' => 'Name', 'variable_name' => 'name', 'value' => mb_substr($customer['name'], 0, 80)]]],
            ], fn ($v) => $v !== null));
        } catch (Throwable) {
            return null;
        }
        $url = $r->json('data.authorization_url');

        return $r->successful() && is_string($url) && str_starts_with($url, 'https://') ? [$url, (string) $r->json('data.access_code')] : null;
    }

    /** @return array{paid: bool, amount_cents: int} */
    public static function verify(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt): array
    {
        try {
            $r = self::http($a)->get(self::API.'/transaction/verify/'.rawurlencode($attempt->reference));
        } catch (Throwable) {
            return ['paid' => false, 'amount_cents' => 0];
        }
        $paid = $r->successful() && $r->json('data.status') === 'success' && $r->json('data.reference') === $attempt->reference
            && strtoupper((string) $r->json('data.currency')) === 'ZAR';

        return ['paid' => $paid, 'amount_cents' => $paid ? (int) $r->json('data.amount', 0) : 0];
    }

    public static function refund(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, int $cents): bool
    {
        try {
            return self::http($a)->post(self::API.'/refund', ['transaction' => $attempt->reference, 'amount' => $cents])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * A signed webhook (x-paystack-signature: HMAC-SHA512 of the body with the secret key): the reference of a successful
     * charge, or null. The charge is still verified with Paystack before it counts.
     *
     * @param  array<string, string>  $headers
     */
    public static function notification(PaymentAccount|PlatformPaymentAccount $a, string $body, array $headers): ?string
    {
        $secret = (string) ($a->credentials['secret_key'] ?? '');
        $signature = strtolower((string) ($headers['x-paystack-signature'] ?? ''));
        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha512', $body, $secret), $signature)) {
            return null;
        }
        $event = json_decode($body, true);

        return is_array($event) && ($event['event'] ?? '') === 'charge.success' && is_string($event['data']['reference'] ?? null) ? $event['data']['reference'] : null;
    }

    /** Whether the key works: Paystack answers a request made with it. */
    public static function check(PaymentAccount|PlatformPaymentAccount $a): bool
    {
        try {
            return self::http($a)->get(self::API.'/balance')->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
