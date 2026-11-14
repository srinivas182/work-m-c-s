<?php

declare(strict_types=1);

namespace Wurk\Success\Console;

use Illuminate\Console\Command;
use Wurk\Success\Support\HealthScores;

/** Nightly: every business's health score (Sprint 16.5). */
final class ScoreCommand extends Command
{
    protected $signature = 'success:score';

    protected $description = 'Work out every business’s health score';

    public function handle(HealthScores $scores): int
    {
        $this->info('Scored '.$scores->scoreAll().' businesses.');

        return self::SUCCESS;
    }
}
