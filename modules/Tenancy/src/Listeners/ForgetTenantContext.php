<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Listeners;

use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Clears the tenant at Octane request boundaries and after every queued job, so
 * a long-lived worker never carries one business's context into the next unit
 * of work.
 */
final readonly class ForgetTenantContext
{
    public function __construct(private TenantContext $context) {}

    public function handle(object $event): void
    {
        $this->context->forget();
    }
}
