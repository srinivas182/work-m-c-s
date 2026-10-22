<?php

declare(strict_types=1);

namespace Wurk\Billing\Gateways;

use Illuminate\Support\Str;

/**
 * Stand-in until WURK chooses a DebiCheck provider (Sprint 3 decision 2): mandates
 * wait for "bank approval" and collections stay pending until an event arrives.
 * Events are signed with the app key. Refuses to run in production, so a fake
 * debit order can never be treated as real money.
 */
final class FakeDebitOrderProvider implements DebitOrderProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function available(): bool
    {
        return ! app()->environment('production');
    }

    public function createMandate(array $mandate): array
    {
        return $this->available()
            ? ['ok' => true, 'provider_id' => 'FAKE-'.Str::upper(Str::random(10)), 'message' => null]
            : ['ok' => false, 'provider_id' => null, 'message' => 'Debit orders are not available yet.'];
    }

    public function collect(string $providerMandateId, int $amountCents, string $reference): GatewayResult
    {
        return $this->available() ? new GatewayResult('pending', $amountCents, 'ZAR') : GatewayResult::failed('Debit orders are not available yet.');
    }

    public function parseEvent(string $body, array $headers): ?array
    {
        if (! $this->available() || ! hash_equals(self::sign($body), $headers['x-fake-signature'] ?? '')) {
            return null;
        }

        $event = json_decode($body, true);
        if (! is_array($event) || ! in_array($event['type'] ?? null, ['mandate.approved', 'mandate.rejected', 'collection.succeeded', 'collection.failed'], true)) {
            return null;
        }

        return [
            'id' => 'fake:'.($event['id'] ?? Str::ulid()),
            'type' => $event['type'],
            'mandate' => $event['mandate'] ?? null,
            'reference' => $event['reference'] ?? null,
            'amount' => isset($event['amount']) ? (int) $event['amount'] : null,
            'message' => $event['message'] ?? null,
        ];
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('app.key'));
    }
}
