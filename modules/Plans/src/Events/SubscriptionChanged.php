<?php

declare(strict_types=1);

namespace Wurk\Plans\Events;

/** A business started a package or changed to another one. */
final readonly class SubscriptionChanged
{
    public function __construct(public string $tenantId, public string $packageKey, public bool $isNew, public int $monthlyCredits) {}
}
