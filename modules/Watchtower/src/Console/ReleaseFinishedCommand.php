<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Console;

use Illuminate\Console\Command;
use Wurk\Watchtower\Support\Releases;

/** Sprint 17.2: the deploy pipeline reports how a release went — recorded, and HQ is told. */
final class ReleaseFinishedCommand extends Command
{
    protected $signature = 'release:finished {version : e.g. v1.0.0}
        {--environment=production : staging or production}
        {--status=live : live, rolled_back or failed}
        {--note= : What happened (no secrets)}';

    protected $description = 'Record a finished deploy and tell HQ';

    public function handle(Releases $releases): int
    {
        $status = (string) $this->option('status');
        if (! in_array($status, Releases::STATUSES, true)) {
            $this->components->error('Status must be one of: '.implode(', ', Releases::STATUSES).'.');

            return self::FAILURE;
        }
        $releases->finished((string) $this->argument('version'), (string) $this->option('environment'), $status, $this->option('note') ? (string) $this->option('note') : null);
        $this->components->info('Recorded.');

        return self::SUCCESS;
    }
}
