<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wurk\Platform\Queues;
use Wurk\Watchtower\Models\Incident;
use Wurk\Watchtower\Support\Analyst;

/** Ask Watchtower's AI to explain one problem (Sprint 18.0b) — in the background, so checks and alerts never wait on it. */
final class AnalyseIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $incidentId)
    {
        $this->onQueue(Queues::BULK); // patient work (18.3a)
    }

    public function handle(Analyst $analyst): void
    {
        $incident = Incident::query()->find($this->incidentId);
        if ($incident !== null) {
            $analyst->analyse($incident);
        }
    }
}
