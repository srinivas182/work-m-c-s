<?php

declare(strict_types=1);

namespace Wurk\Payments\Gateways\MobileMoney;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;

/**
 * MTN MoMo Collections, "Request to Pay", on the business's own MoMo account. The sandbox uses EUR and the
 * "sandbox" environment; live accounts name their market (e.g. mtnghana). Callbacks aren't signed: the status is
 * always read back.
 */
final class MtnMomo implements MobileMoneyGateway
{
    public const CURRENCIES = ['GH' => 'GHS', 'UG' => 'UGX', 'ZM' => 'ZMW', 'CM' => 'XAF', 'CI' => 'XOF', 'RW' => 'RWF'];

    /** MTN's failure reasons, in plain ones. */
    private const REASONS = ['APPROVAL_REJECTED' => 'declined', 'EXPIRED' => 'timeout', 'NOT_ENOUGH_FUNDS' => 'funds', 'PAYER_LIMIT_REACHED' => 'funds'];

    private function base(PaymentAccount $a): string
    {
        return $a->mode === 'live' ? 'https://proxy.momoapi.mtn.com' : 'https://sandbox.momodeveloper.mtn.com';
    }

    private function environment(PaymentAccount $a): string
    {
        return $a->mode === 'live' ? (string) ($a->credentials['environment'] ?? '') : 'sandbox';
    }

    private function http(PaymentAccount $a): PendingRequest
    {
        return Http::timeout(20)->acceptJson()->withHeaders(['Ocp-Apim-Subscription-Key' => (string) ($a->credentials['subscription_key'] ?? ''), 'X-Target-Environment' => $this->environment($a)]);
    }

    private function token(PaymentAccount $a): ?string
    {
        $user = (string) ($a->credentials['api_user'] ?? '');

        return Cache::remember('momo-token:'.hash('sha256', $a->mode.$user), 3000, function () use ($a, $user): ?string {
            try {
                $r = $this->http($a)->withBasicAuth($user, (string) ($a->credentials['api_key'] ?? ''))->post($this->base($a).'/collection/token/');
            } catch (Throwable) {
                return null;
            }

            return $r->successful() && is_string($r->json('access_token')) ? $r->json('access_token') : null;
        }) ?: null;
    }

    private function currency(PaymentAccount $a, string $country): string
    {
        return $a->mode === 'live' ? (self::CURRENCIES[$country] ?? 'EUR') : 'EUR';
    }

    public function request(PaymentAccount $account, PaymentAttempt $attempt, string $msisdn, string $country, string $callbackUrl): array
    {
        $token = $this->token($account);
        if ($token === null) {
            return [null, 'other'];
        }
        $id = (string) Str::uuid();
        try {
            $r = $this->http($account)->withToken($token)->withHeaders(['X-Reference-Id' => $id, 'X-Callback-Url' => $callbackUrl])
                ->post($this->base($account).'/collection/v1_0/requesttopay', [
                    'amount' => (string) (int) ceil($attempt->amount_cents / 100), 'currency' => $this->currency($account, $country),
                    'externalId' => $attempt->reference, 'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $msisdn],
                    'payerMessage' => 'Payment', 'payeeNote' => $attempt->reference,
                ]);
        } catch (Throwable) {
            return [null, 'other'];
        }

        return $r->status() === 202 ? [$id, null] : [null, 'other'];
    }

    public function status(PaymentAccount $account, PaymentAttempt $attempt, string $country): MobileMoneyResult
    {
        $token = $this->token($account);
        if ($token === null || $attempt->provider_id === null) {
            return MobileMoneyResult::pending();
        }
        try {
            $r = $this->http($account)->withToken($token)->get($this->base($account).'/collection/v1_0/requesttopay/'.rawurlencode($attempt->provider_id));
        } catch (Throwable) {
            return MobileMoneyResult::pending();
        }

        return match ($r->successful() ? $r->json('status') : null) {
            'SUCCESSFUL' => $r->json('externalId') === $attempt->reference && $r->json('currency') === $this->currency($account, $country)
                ? MobileMoneyResult::paid((int) round((float) $r->json('amount') * 100)) : MobileMoneyResult::failed('other'),
            'FAILED', 'REJECTED', 'TIMEOUT' => MobileMoneyResult::failed(self::REASONS[(string) $r->json('reason')] ?? ($r->json('status') === 'TIMEOUT' ? 'timeout' : 'other')),
            default => MobileMoneyResult::pending(),
        };
    }

    public function callbackId(array $payload): ?string
    {
        // MTN's callback carries the externalId (our reference) but not always the request id; the page looks it up.
        $id = $payload['referenceId'] ?? $payload['externalId'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function check(PaymentAccount $account): bool
    {
        return $this->token($account) !== null;
    }
}
