<?php

declare(strict_types=1);

namespace Wurk\Payments\Gateways\MobileMoney;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;

/**
 * Airtel Money (Airtel Africa's open API): a USSD push collection to the business's own Airtel merchant account.
 * The number goes without its country code; country and currency go in headers. Results are read back with the
 * transaction enquiry, never taken from the callback alone.
 */
final class AirtelMoney implements MobileMoneyGateway
{
    public const CURRENCIES = ['KE' => 'KES', 'UG' => 'UGX', 'TZ' => 'TZS', 'ZM' => 'ZMW', 'MW' => 'MWK', 'RW' => 'RWF'];

    public const DIAL = ['KE' => '254', 'UG' => '256', 'TZ' => '255', 'ZM' => '260', 'MW' => '265', 'RW' => '250'];

    private function base(PaymentAccount $a): string
    {
        return $a->mode === 'live' ? 'https://openapi.airtel.africa' : 'https://openapiuat.airtel.africa';
    }

    private function token(PaymentAccount $a): ?string
    {
        $id = (string) ($a->credentials['client_id'] ?? '');

        return Cache::remember('airtel-token:'.hash('sha256', $a->mode.$id), 3000, function () use ($a, $id): ?string {
            try {
                $r = Http::timeout(15)->acceptJson()->post($this->base($a).'/auth/oauth2/token', ['client_id' => $id, 'client_secret' => (string) ($a->credentials['client_secret'] ?? ''), 'grant_type' => 'client_credentials']);
            } catch (Throwable) {
                return null;
            }

            return $r->successful() && is_string($r->json('access_token')) ? $r->json('access_token') : null;
        }) ?: null;
    }

    /** @return array<string, string> */
    private function headers(string $country): array
    {
        return ['X-Country' => $country, 'X-Currency' => self::CURRENCIES[$country] ?? 'KES'];
    }

    public function request(PaymentAccount $account, PaymentAttempt $attempt, string $msisdn, string $country, string $callbackUrl): array
    {
        $token = $this->token($account);
        if ($token === null || ! isset(self::CURRENCIES[$country])) {
            return [null, 'other'];
        }
        $local = str_starts_with($msisdn, self::DIAL[$country]) ? substr($msisdn, strlen(self::DIAL[$country])) : $msisdn;
        $id = substr($attempt->reference, -20);
        try {
            $r = Http::timeout(20)->acceptJson()->withToken($token)->withHeaders($this->headers($country))->post($this->base($account).'/merchant/v1/payments/', [
                'reference' => 'Payment', 'subscriber' => ['country' => $country, 'currency' => self::CURRENCIES[$country], 'msisdn' => $local],
                'transaction' => ['amount' => (int) ceil($attempt->amount_cents / 100), 'country' => $country, 'currency' => self::CURRENCIES[$country], 'id' => $id],
            ]);
        } catch (Throwable) {
            return [null, 'other'];
        }

        return $r->successful() && $r->json('status.success') === true ? [$id, null] : [null, 'other'];
    }

    public function status(PaymentAccount $account, PaymentAttempt $attempt, string $country): MobileMoneyResult
    {
        $token = $this->token($account);
        if ($token === null || $attempt->provider_id === null) {
            return MobileMoneyResult::pending();
        }
        try {
            $r = Http::timeout(15)->acceptJson()->withToken($token)->withHeaders($this->headers($country))->get($this->base($account).'/standard/v1/payments/'.rawurlencode($attempt->provider_id));
        } catch (Throwable) {
            return MobileMoneyResult::pending();
        }
        $message = strtolower((string) $r->json('data.transaction.message'));

        return match ($r->successful() ? $r->json('data.transaction.status') : null) {
            // The enquiry doesn't repeat the amount; Airtel charges exactly the one requested.
            'TS' => MobileMoneyResult::paid((int) ceil($attempt->amount_cents / 100) * 100),
            'TF' => MobileMoneyResult::failed(match (true) {
                str_contains($message, 'insufficient') => 'funds',
                str_contains($message, 'pin') => 'pin',
                str_contains($message, 'time') || str_contains($message, 'expired') => 'timeout',
                str_contains($message, 'cancel') || str_contains($message, 'declin') || str_contains($message, 'reject') => 'declined',
                default => 'other',
            }),
            default => MobileMoneyResult::pending(), // TIP (in progress) or TA (ambiguous): ask again
        };
    }

    public function callbackId(array $payload): ?string
    {
        $id = $payload['transaction']['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function check(PaymentAccount $account): bool
    {
        return $this->token($account) !== null;
    }
}
