<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Listeners;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
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
        // A job run synchronously is part of the current unit of work (a request or a command):
        // its own run() already restored our context, and clearing it here would leave the
        // caller without one. Workers ("redis", "database", …) still reset after every job.
        if (($event instanceof JobProcessed || $event instanceof JobExceptionOccurred) && $event->connectionName === 'sync') {
            return;
        }
        $this->context->forget();
    }
}
