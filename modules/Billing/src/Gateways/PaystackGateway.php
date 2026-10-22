<?php

declare(strict_types=1);

namespace Wurk\Billing\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Wurk\Messaging\Contracts\ProviderCredentials;

/**
 * Paystack (api.paystack.co). Amounts are in the currency's subunit (cents for ZAR).
 * Paystack only sends a webhook for successful charges (charge.success); a failed
 * charge is known from the charge response or the verify API.
 */
final readonly class PaystackGateway implements PaymentGateway
{
    public const BASE = 'https://api.paystack.co';

    public function __construct(private ProviderCredentials $providers) {}

    public function name(): string
    {
        return 'paystack';
    }

    public function checkout(string $email, int $amountCents, string $currency, string $reference, string $callbackUrl, array $metadata): ?string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post(self::BASE.'/transaction/initialize', [
            'email' => $email, 'amount' => $amountCents, 'currency' => $currency, 'reference' => $reference,
            'callback_url' => $callbackUrl, 'channels' => ['card'], 'metadata' => $metadata,
        ]));

        $url = $response?->json('data.authorization_url');

        return $response?->successful() && is_string($url) ? $url : null;
    }

    public function verify(string $reference): GatewayResult
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get(self::BASE.'/transaction/verify/'.rawurlencode($reference)));

        return $response === null ? GatewayResult::failed('Paystack unreachable') : $this->result($response);
    }

    public function chargeSaved(string $authorization, string $email, int $amountCents, string $currency, string $reference): GatewayResult
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post(self::BASE.'/transaction/charge_authorization', [
            'authorization_code' => $authorization, 'email' => $email, 'amount' => $amountCents, 'currency' => $currency, 'reference' => $reference,
        ]));

        return $response === null ? GatewayResult::failed('Paystack unreachable') : $this->result($response);
    }

    public function refund(string $reference, ?int $amountCents = null): bool
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post(self::BASE.'/refund', array_filter(['transaction' => $reference, 'amount' => $amountCents])));

        return (bool) $response?->successful();
    }

    public function parseWebhook(string $body, array $headers): ?array
    {
        $secret = $this->secret();
        $signature = $headers['x-paystack-signature'] ?? '';
        if ($secret === '' || ! hash_equals(hash_hmac('sha512', $body, $secret), $signature)) {
            return null;
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ($event['event'] ?? '') !== 'charge.success' || ! is_string($event['data']['reference'] ?? null)) {
            return null;
        }

        return ['id' => 'paystack:'.($event['data']['id'] ?? $event['data']['reference']), 'type' => 'charge.success', 'reference' => $event['data']['reference']];
    }

    private function result(Response $response): GatewayResult
    {
        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data)) {
            return GatewayResult::failed('Paystack: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        $auth = is_array($data['authorization'] ?? null) ? $data['authorization'] : null;
        $status = match ($data['status'] ?? '') {
            'success' => 'succeeded',
            'failed', 'abandoned', 'reversed' => 'failed',
            default => 'pending',
        };

        return new GatewayResult(
            status: $status,
            amountCents: isset($data['amount']) ? (int) $data['amount'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            gatewayId: isset($data['id']) ? (string) $data['id'] : null,
            card: $auth === null || ! isset($auth['authorization_code']) ? null : [
                'code' => (string) $auth['authorization_code'],
                'reusable' => (bool) ($auth['reusable'] ?? false),
                'signature' => (string) ($auth['signature'] ?? $auth['authorization_code']),
                'brand' => isset($auth['brand']) ? ucfirst((string) $auth['brand']) : null,
                'last4' => isset($auth['last4']) ? (string) $auth['last4'] : null,
                'expires' => isset($auth['exp_month'], $auth['exp_year']) ? sprintf('%02d/%s', (int) $auth['exp_month'], $auth['exp_year']) : null,
            ],
            message: $status === 'failed' ? (string) ($data['gateway_response'] ?? $response->json('message') ?? 'Declined') : null,
        );
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call): ?Response
    {
        if ($this->secret() === '') {
            return null;
        }

        try {
            return $call(Http::timeout(15)->acceptJson()->withToken($this->secret()));
        } catch (ConnectionException) {
            return null;
        }
    }

    private function secret(): string
    {
        return (string) ($this->providers->active('payments.paystack')['credentials']['secret_key'] ?? '');
    }
}
