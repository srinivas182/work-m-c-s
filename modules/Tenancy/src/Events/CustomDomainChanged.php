<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Events;

/** Sprint 17.3: a custom domain became active, or lapsed (stopped pointing at WURK). The owner is told. */
final readonly class CustomDomainChanged
{
    public function __construct(public string $tenantId, public string $host, public string $status) {}
}
