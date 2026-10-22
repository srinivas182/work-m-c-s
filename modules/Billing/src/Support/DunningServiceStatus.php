<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Wurk\Billing\Contracts\ServiceStatus;

final readonly class DunningServiceStatus implements ServiceStatus
{
    public function __construct(private Dunning $dunning) {}

    public function suspendedForNonPayment(string $tenantId): bool
    {
        return $this->dunning->stage($tenantId) === 'suspended';
    }
}
