<?php

declare(strict_types=1);

namespace Wurk\Payments\Gateways;

use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;

/**
 * Ozow (instant EFT, the business's own site). The customer's browser posts a hashed form to Ozow; a
 * notification counts only if its hash checks out AND Ozow's API confirms the transaction and amount.
 * Ozow refunds aren't automated: the owner refunds in Ozow's portal and records it.
 */
final class Ozow
{
    private const FORM_ORDER = ['SiteCode', 'CountryCode', 'CurrencyCode', 'Amount', 'TransactionReference', 'BankReference', 'CancelUrl', 'ErrorUrl', 'SuccessUrl', 'NotifyUrl', 'IsTest'];

    private const NOTIFY_ORDER = ['SiteCode', 'TransactionId', 'TransactionReference', 'Amount', 'Status', 'Optional1', 'Optional2', 'Optional3', 'Optional4', 'Optional5', 'CurrencyCode', 'IsTest', 'StatusMessage'];

    /**
     * Ozow's hash: SHA-512 of the values in order plus the private key, all lowercased.
     *
     * @param  array<array-key, string>  $values
     */
    public static function hash(array $values, string $privateKey): string
    {
        return hash('sha512', strtolower(implode('', array_map('strval', $values)).$privateKey));
    }

    /**
     * @param  array{return: string, cancel: string, notify: string}  $urls
     * @return array{action: string, fields: array<string, string>}
     */
    public static function form(PaymentAccount $a, PaymentAttempt $attempt, array $urls): array
    {
        $fields = [
            'SiteCode' => (string) ($a->credentials['site_code'] ?? ''), 'CountryCode' => 'ZA', 'CurrencyCode' => 'ZAR',
            'Amount' => number_format($attempt->amount_cents / 100, 2, '.', ''), 'TransactionReference' => $attempt->reference,
            'BankReference' => substr(preg_replace('/[^A-Za-z0-9 ]/', '', $attempt->reference) ?? '', -20),
            'CancelUrl' => $urls['cancel'], 'ErrorUrl' => $urls['cancel'], 'SuccessUrl' => $urls['return'], 'NotifyUrl' => $urls['notify'],
            'IsTest' => $a->mode === 'live' ? 'false' : 'true',
        ];
        $ordered = [];
        foreach (self::FORM_ORDER as $k) {
            $ordered[$k] = $fields[$k];
        }

        return ['action' => (string) config('payments.ozow.pay'), 'fields' => [...$ordered, 'HashCheck' => self::hash($ordered, (string) ($a->credentials['private_key'] ?? ''))]];
    }

    /**
     * A notification, if its hash checks out: the attempt's reference and Ozow's transaction id.
     *
     * @param  array<string, string>  $posted
     * @return array{reference: string, provider_id: string}|null
     */
    public static function notification(PaymentAccount $a, array $posted): ?array
    {
        $values = array_map(fn (string $k) => $posted[$k] ?? '', self::NOTIFY_ORDER);
        if (! hash_equals(self::hash($values, (string) ($a->credentials['private_key'] ?? '')), strtolower((string) ($posted['Hash'] ?? '')))) {
            return null;
        }

        return ['reference' => (string) ($posted['TransactionReference'] ?? ''), 'provider_id' => (string) ($posted['TransactionId'] ?? '')];
    }

    /**
     * Ask Ozow directly.
     *
     * @return array{paid: bool, amount_cents: int}
     */
    public static function verify(PaymentAccount $a, PaymentAttempt $attempt): array
    {
        try {
            $r = Http::timeout(20)->acceptJson()->withHeaders(['ApiKey' => (string) ($a->credentials['api_key'] ?? '')])
                ->get(config('payments.ozow.api').'/GetTransactionByReference', ['siteCode' => $a->credentials['site_code'] ?? '', 'transactionReference' => $attempt->reference, 'isTest' => $a->mode === 'live' ? 'false' : 'true']);
        } catch (Throwable) {
            return ['paid' => false, 'amount_cents' => 0];
        }
        $paid = collect((array) $r->json())->first(fn ($t) => is_array($t) && ($t['status'] ?? '') === 'Complete');

        return ['paid' => $r->successful() && $paid !== null, 'amount_cents' => $paid === null ? 0 : (int) round((float) ($paid['amount'] ?? 0) * 100)];
    }
}
