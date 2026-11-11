<?php

declare(strict_types=1);

namespace Wurk\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Wurk\Billing\Support\AutoTopUp;

/** Off the sending path: a card charge never slows a message down (Sprint 15.5b). */
final class AutoTopUpJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1; // never charge twice by retrying

    public function __construct(public string $tenantId) {}

    public function handle(AutoTopUp $topUp): void
    {
        $topUp->run($this->tenantId);
    }
}
