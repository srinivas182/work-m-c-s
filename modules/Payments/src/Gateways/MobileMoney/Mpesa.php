<?php

declare(strict_types=1);

namespace Wurk\Payments\Gateways\MobileMoney;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;

/**
 * Safaricom M-Pesa through Daraja: "Lipa na M-Pesa" STK push to the business's own Paybill or Till (Buy Goods).
 * Amounts are whole shillings. Daraja's callbacks aren't signed, so every result is read back with the STK query.
 */
final class Mpesa implements MobileMoneyGateway
{
    /** Daraja's result codes, in plain reasons. */
    private const REASONS = ['1032' => 'declined', '1037' => 'timeout', '1019' => 'timeout', '1' => 'funds', '2001' => 'pin'];

    private function base(PaymentAccount $a): string
    {
        return $a->mode === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }

    private function token(PaymentAccount $a): ?string
    {
        $key = (string) ($a->credentials['consumer_key'] ?? '');

        return Cache::remember('mpesa-token:'.hash('sha256', $a->mode.$key), 3000, function () use ($a, $key): ?string {
            try {
                $r = Http::timeout(15)->withBasicAuth($key, (string) ($a->credentials['consumer_secret'] ?? ''))
                    ->get($this->base($a).'/oauth/v1/generate', ['grant_type' => 'client_credentials']);
            } catch (Throwable) {
                return null;
            }

            return $r->successful() && is_string($r->json('access_token')) ? $r->json('access_token') : null;
        }) ?: null;
    }

    /** @return array{BusinessShortCode: string, Password: string, Timestamp: string} */
    private function signed(PaymentAccount $a): array
    {
        $shortcode = (string) ($a->credentials['shortcode'] ?? '');
        $timestamp = now('Africa/Nairobi')->format('YmdHis');

        return ['BusinessShortCode' => $shortcode, 'Password' => base64_encode($shortcode.($a->credentials['passkey'] ?? '').$timestamp), 'Timestamp' => $timestamp];
    }

    public function request(PaymentAccount $account, PaymentAttempt $attempt, string $msisdn, string $country, string $callbackUrl): array
    {
        $token = $this->token($account);
        if ($token === null) {
            return [null, 'other'];
        }
        $till = (string) ($account->credentials['till'] ?? '');
        try {
            $r = Http::timeout(20)->withToken($token)->post($this->base($account).'/mpesa/stkpush/v1/processrequest', [
                ...$this->signed($account),
                'TransactionType' => $till !== '' ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
                'Amount' => (int) ceil($attempt->amount_cents / 100), 'PartyA' => $msisdn, 'PhoneNumber' => $msisdn,
                'PartyB' => $till !== '' ? $till : (string) ($account->credentials['shortcode'] ?? ''),
                'CallBackURL' => $callbackUrl, 'AccountReference' => substr($attempt->reference, -12), 'TransactionDesc' => 'Payment',
            ]);
        } catch (Throwable) {
            return [null, 'other'];
        }
        $id = $r->json('CheckoutRequestID');

        return $r->successful() && (string) $r->json('ResponseCode') === '0' && is_string($id) ? [$id, null] : [null, 'other'];
    }

    public function status(PaymentAccount $account, PaymentAttempt $attempt, string $country): MobileMoneyResult
    {
        $token = $this->token($account);
        if ($token === null || $attempt->provider_id === null) {
            return MobileMoneyResult::pending();
        }
        try {
            $r = Http::timeout(15)->withToken($token)->post($this->base($account).'/mpesa/stkpushquery/v1/query', [...$this->signed($account), 'CheckoutRequestID' => $attempt->provider_id]);
        } catch (Throwable) {
            return MobileMoneyResult::pending();
        }
        $code = $r->json('ResultCode');
        if ($code === null) {
            return MobileMoneyResult::pending(); // "The transaction is being processed"
        }
        if ((string) $code === '0') {
            // The query doesn't repeat the amount; it is the one requested (Daraja charges exactly that).
            return MobileMoneyResult::paid((int) ceil($attempt->amount_cents / 100) * 100);
        }

        return MobileMoneyResult::failed(self::REASONS[(string) $code] ?? 'other');
    }

    public function callbackId(array $payload): ?string
    {
        $id = $payload['Body']['stkCallback']['CheckoutRequestID'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function check(PaymentAccount $account): bool
    {
        return $this->token($account) !== null;
    }
}
