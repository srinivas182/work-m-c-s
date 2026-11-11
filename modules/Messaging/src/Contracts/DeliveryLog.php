<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/** What WURK sent through each provider (Sprint 15.5c — for reconciling a provider's statement). Read-only. */
interface DeliveryLog
{
    /**
     * Messages a provider accepted in a period (failed sends excluded — providers don't bill those).
     *
     * @return list<array{id: string, channel: string, provider_message_id: string|null, to_hash: string, day: string, tenant_id: string|null}>
     */
    public function sent(string $driver, \DateTimeInterface $from, \DateTimeInterface $to): array;

    /** @return list<string> the providers WURK has sent through */
    public function drivers(): array;

    /** The same one-way hash WURK keeps for a number, from any common way of writing it (082…, 27…, +27…); null if not a number. */
    public function hashFor(string $number): ?string;
}
