<?php

declare(strict_types=1);

namespace Wurk\Billing\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Wurk\Billing\Contracts\SplitPayments;
use Wurk\Messaging\Contracts\ProviderCredentials;

/** Paystack subaccounts: deposits paid straight to the business; Paystack's fee borne by the business. */
final readonly class PaystackSplitPayments implements SplitPayments
{
    public function __construct(private ProviderCredentials $providers) {}

    public function banks(): array
    {
        return Cache::remember('paystack:banks:za', 86400, function (): array {
            $r = $this->send(fn ($http) => $http->get(PaystackGateway::BASE.'/bank', ['country' => 'south africa', 'currency' => 'ZAR']));
            $banks = [];
            foreach ((array) ($r?->json('data') ?? []) as $b) {
                $banks[] = ['code' => (string) ($b['code'] ?? ''), 'name' => (string) ($b['name'] ?? '')];
            }

            return $banks;
        });
    }

    public function createSubaccount(string $businessName, string $bankCode, string $accountNumber): string
    {
        $r = $this->send(fn ($http) => $http->post(PaystackGateway::BASE.'/subaccount', [
            'business_name' => $businessName, 'settlement_bank' => $bankCode, 'account_number' => $accountNumber, 'percentage_charge' => 0,
        ]));
        $code = (string) ($r?->json('data.subaccount_code') ?? '');
        if ($r === null || ! $r->successful() || $code === '') {
            throw new RuntimeException((string) ($r?->json('message') ?: 'Paystack couldn’t set up the account. Check the bank and account number.'));
        }

        return $code;
    }

    public function checkout(string $email, int $amountCents, string $reference, string $callbackUrl, string $subaccount, array $metadata): ?string
    {
        $r = $this->send(fn ($http) => $http->post(PaystackGateway::BASE.'/transaction/initialize', [
            'email' => $email, 'amount' => $amountCents, 'currency' => 'ZAR', 'reference' => $reference, 'callback_url' => $callbackUrl,
            'subaccount' => $subaccount, 'bearer' => 'subaccount', 'metadata' => $metadata,
        ]));

        return $r?->successful() ? ($r->json('data.authorization_url') ?: null) : null;
    }

    public function verify(string $reference): array
    {
        $r = $this->send(fn ($http) => $http->get(PaystackGateway::BASE.'/transaction/verify/'.rawurlencode($reference)));

        return ['paid' => $r?->successful() === true && $r->json('data.status') === 'success', 'amount_cents' => (int) ($r?->json('data.amount') ?? 0)];
    }

    public function refund(string $reference): bool
    {
        return $this->send(fn ($http) => $http->post(PaystackGateway::BASE.'/refund', ['transaction' => $reference]))?->successful() === true;
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call): ?Response
    {
        $secret = (string) ($this->providers->active('payments.paystack')['credentials']['secret_key'] ?? '');
        if ($secret === '') {
            return null;
        }
        try {
            return $call(Http::timeout(15)->acceptJson()->withToken($secret));
        } catch (ConnectionException) {
            return null;
        }
    }
}
