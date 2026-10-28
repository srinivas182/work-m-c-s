<?php

declare(strict_types=1);

namespace Wurk\Payments\Gateways;

use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Models\PlatformPaymentAccount;
use Wurk\Payments\Models\PlatformPaymentAttempt;

/**
 * PayFast (the business's own merchant account). The customer's browser POSTs signed fields to PayFast;
 * a payment counts only after its notification (ITN) passes all four checks: signature, PayFast's own
 * validation ("VALID"), status COMPLETE, and the exact amount.
 */
final class PayFast
{
    /** PayFast's field order for the signature. */
    private const ORDER = ['merchant_id', 'merchant_key', 'return_url', 'cancel_url', 'notify_url', 'name_first', 'email_address', 'm_payment_id', 'amount', 'item_name'];

    public static function host(PaymentAccount|PlatformPaymentAccount $a): string
    {
        return $a->mode === 'live' ? 'www.payfast.co.za' : 'sandbox.payfast.co.za';
    }

    /**
     * The signed fields for the customer's browser to post to PayFast.
     *
     * @param  array{name: string, email?: string|null}  $customer
     * @param  array{return: string, cancel: string, notify: string}  $urls
     * @return array<string, string>
     */
    public static function fields(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, string $description, array $customer, array $urls): array
    {
        $fields = array_filter([
            'merchant_id' => $a->credentials['merchant_id'] ?? '', 'merchant_key' => $a->credentials['merchant_key'] ?? '',
            'return_url' => $urls['return'], 'cancel_url' => $urls['cancel'], 'notify_url' => $urls['notify'],
            'name_first' => mb_substr($customer['name'], 0, 100), 'email_address' => (string) ($customer['email'] ?? ''),
            'm_payment_id' => $attempt->reference, 'amount' => number_format($attempt->amount_cents / 100, 2, '.', ''), 'item_name' => mb_substr($description, 0, 100),
        ], fn ($v) => $v !== '');
        $ordered = [];
        foreach (self::ORDER as $key) { // PayFast's order
            if (isset($fields[$key])) {
                $ordered[$key] = (string) $fields[$key];
            }
        }

        return [...$ordered, 'signature' => self::signature($ordered, (string) ($a->credentials['passphrase'] ?? ''))];
    }

    /**
     * @param  array{name: string, email?: string|null}  $customer
     * @param  array{return: string, cancel: string, notify: string}  $urls
     * @return array{action: string, fields: array<string, string>}
     */
    public static function form(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, string $description, array $customer, array $urls): array
    {
        return ['action' => 'https://'.self::host($a).'/eng/process', 'fields' => self::fields($a, $attempt, $description, $customer, $urls)];
    }

    /** @param  array<string, string>  $fields  in the order they're sent */
    public static function signature(array $fields, string $passphrase): string
    {
        $pairs = [];
        foreach ($fields as $k => $v) {
            $pairs[] = $k.'='.urlencode(trim($v));
        }
        $string = implode('&', $pairs).($passphrase !== '' ? '&passphrase='.urlencode(trim($passphrase)) : '');

        return md5($string);
    }

    /**
     * A notification from PayFast, if it passes every check; the attempt's reference and PayFast's id.
     *
     * @param  array<string, string>  $posted  in the order received
     * @return array{reference: string, provider_id: string}|null
     */
    public static function notification(PaymentAccount|PlatformPaymentAccount $a, array $posted, PaymentAttempt|PlatformPaymentAttempt|null $attempt): ?array
    {
        $signature = (string) ($posted['signature'] ?? '');
        $fields = $posted;
        unset($fields['signature']);
        if ($signature === '' || ! hash_equals(self::signature($fields, (string) ($a->credentials['passphrase'] ?? '')), $signature)) {
            return null;
        }
        if ($attempt === null || $attempt->reference !== (string) ($posted['m_payment_id'] ?? '') || ($posted['payment_status'] ?? '') !== 'COMPLETE'
            || (int) round((float) ($posted['amount_gross'] ?? 0) * 100) !== $attempt->amount_cents) {
            return null;
        }
        try { // PayFast's own confirmation that it sent this notification
            $pairs = [];
            foreach ($fields as $k => $v) {
                $pairs[] = $k.'='.urlencode($v);
            }
            $valid = Http::asForm()->timeout(15)->withBody(implode('&', $pairs), 'application/x-www-form-urlencoded')
                ->post('https://'.self::host($a).'/eng/query/validate')->body();
        } catch (Throwable) {
            return null;
        }

        return trim($valid) === 'VALID' ? ['reference' => $attempt->reference, 'provider_id' => (string) ($posted['pf_payment_id'] ?? '')] : null;
    }

    /** PayFast's refunds API (where enabled on the account); false means the owner refunds by hand. */
    public static function refund(PaymentAccount|PlatformPaymentAccount $a, PaymentAttempt|PlatformPaymentAttempt $attempt, int $cents): bool
    {
        if ($attempt->provider_id === null || $attempt->provider_id === '') {
            return false;
        }
        $headers = ['merchant-id' => (string) ($a->credentials['merchant_id'] ?? ''), 'version' => 'v1', 'timestamp' => now()->toIso8601String()];
        $body = ['amount' => (string) $cents, 'reason' => 'Refund'];
        $all = [...$headers, ...$body, 'passphrase' => (string) ($a->credentials['passphrase'] ?? '')];
        ksort($all);
        $headers['signature'] = md5(http_build_query($all));
        try {
            $url = 'https://api.payfast.co.za/refunds/'.rawurlencode($attempt->provider_id).($a->mode === 'live' ? '' : '?testing=true');

            return Http::timeout(20)->withHeaders($headers)->asForm()->post($url, $body)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
