<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;
use Wurk\Watchtower\Support\Backups;

/** Sprint 18.4c: record a restore drill's timings against the targets (data lost ≤ 15 minutes, back within 4 hours). */
final class DrillRecordedCommand extends Command
{
    protected $signature = 'system:drill-recorded
        {--scope=shard : shard, business or everything}
        {--target= : Which shard or business}
        {--failed= : When the (pretend) disaster happened}
        {--restored= : When it was working again (default now)}
        {--backup= : The point in time the backup restored to}
        {--note= : What was done and anything learnt}
        {--by= : Who ran it}';

    protected $description = 'Record a restore drill (shown on Admin → System)';

    public function handle(): int
    {
        $scope = (string) $this->option('scope');
        if (! in_array($scope, Backups::SCOPES, true)) {
            $this->components->error('Scope must be one of: '.implode(', ', Backups::SCOPES).'.');

            return self::FAILURE;
        }
        try {
            $failed = CarbonImmutable::parse((string) ($this->option('failed') ?: throw new \InvalidArgumentException), Backups::tz());
            $restored = $this->option('restored') ? CarbonImmutable::parse((string) $this->option('restored'), Backups::tz()) : CarbonImmutable::now();
            $backup = $this->option('backup') ? CarbonImmutable::parse((string) $this->option('backup'), Backups::tz()) : null;
        } catch (Throwable) {
            $this->components->error('Give --failed (and optionally --restored and --backup) as dates.');

            return self::FAILURE;
        }
        if ($restored->lessThan($failed)) {
            $this->components->error('It can’t be restored before it failed.');

            return self::FAILURE;
        }
        $r = Backups::recordDrill($scope, $this->option('target') ? (string) $this->option('target') : null, $failed, $restored, $backup,
            $this->option('note') ? (string) $this->option('note') : null, $this->option('by') ? (string) $this->option('by') : null);
        $loss = $r['data_loss_minutes'] === null ? 'not measured' : $r['data_loss_minutes'].' min (target ≤ '.config('wurk.watchtower.rpo_minutes').')';
        $this->components->info(($r['passed'] ? 'Passed' : 'Missed the targets').' — back in '.$r['restore_minutes'].' min (target ≤ '.config('wurk.watchtower.rto_minutes').'); data lost: '.$loss.'.');

        return $r['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
