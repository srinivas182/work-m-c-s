<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;
use Wurk\Watchtower\Support\Backups;

/** Sprint 18.4c: a backup job reports a finished backup (shown on Admin → System; a missing one raises a problem). */
final class BackupTakenCommand extends Command
{
    protected $signature = 'system:backup-taken {target : What was backed up — central, a shard name, files…}
        {--kind=full : full, incremental, snapshot or files}
        {--at= : When it was taken (default now; any date PHP understands, WURK’s time zone unless it says otherwise)}
        {--size= : Size in bytes}
        {--location= : Where it is kept (no secrets)}
        {--by= : The job or person reporting}';

    protected $description = 'Record a finished backup (for backup jobs to call)';

    public function handle(): int
    {
        $kind = (string) $this->option('kind');
        if (! in_array($kind, Backups::KINDS, true)) {
            $this->components->error('Kind must be one of: '.implode(', ', Backups::KINDS).'.');

            return self::FAILURE;
        }
        try {
            $at = $this->option('at') ? CarbonImmutable::parse((string) $this->option('at'), Backups::tz()) : CarbonImmutable::now();
        } catch (Throwable) {
            $this->components->error('That isn’t a date.');

            return self::FAILURE;
        }
        $size = $this->option('size');
        Backups::record((string) $this->argument('target'), $kind, $at, is_numeric($size) ? (int) $size : null,
            $this->option('location') ? (string) $this->option('location') : null, $this->option('by') ? (string) $this->option('by') : null);
        $this->components->info('Recorded the '.$kind.' backup of '.$this->argument('target').' taken '.$at->setTimezone(Backups::tz())->format('Y-m-d H:i').'.');

        return self::SUCCESS;
    }
}
