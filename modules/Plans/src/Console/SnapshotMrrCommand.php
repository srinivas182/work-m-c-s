<?php

declare(strict_types=1);

namespace Wurk\Plans\Console;

use Illuminate\Console\Command;
use Wurk\Plans\Support\MrrSnapshots;

/** Daily: each business's subscription as it stands today (Sprint 15.4 — MRR movement and cohorts come from these). */
final class SnapshotMrrCommand extends Command
{
    protected $signature = 'plans:snapshot-mrr';

    protected $description = "Record each business's subscription and MRR for today";

    public function handle(MrrSnapshots $snapshots): int
    {
        $this->info('Recorded '.$snapshots->take().' businesses.');

        return self::SUCCESS;
    }
}
