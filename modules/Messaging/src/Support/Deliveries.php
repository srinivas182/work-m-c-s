<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Carbon\CarbonImmutable;
use Wurk\Messaging\Contracts\DeliveryLog;
use Wurk\Messaging\Models\MessageDelivery;

final class Deliveries implements DeliveryLog
{
    public function sent(string $driver, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return array_values(MessageDelivery::query()->where('driver', $driver)->where('status', '!=', 'failed')
            ->whereBetween('created_at', [$from, $to])->get(['id', 'channel', 'provider_message_id', 'to_hash', 'created_at', 'tenant_id'])
            ->map(fn (MessageDelivery $d) => ['id' => (string) $d->id, 'channel' => (string) $d->channel, 'provider_message_id' => $d->provider_message_id, 'to_hash' => (string) $d->to_hash,
                'day' => CarbonImmutable::parse($d->created_at)->setTimezone('Africa/Johannesburg')->toDateString(), 'tenant_id' => $d->tenant_id])->all());
    }

    public function drivers(): array
    {
        return array_values(array_map('strval', MessageDelivery::query()->distinct()->orderBy('driver')->pluck('driver')->all()));
    }

    public function hashFor(string $number): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $number);
        $e164 = match (true) {
            strlen($digits) === 10 && str_starts_with($digits, '0') => '+27'.substr($digits, 1), // a South African number written locally
            strlen($digits) >= 10 && strlen($digits) <= 15 => '+'.$digits,
            default => null,
        };

        return $e164 === null ? null : hash_hmac('sha256', $e164, (string) config('app.key')); // as MessengerService records it
    }
}
