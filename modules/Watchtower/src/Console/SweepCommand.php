<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Watchtower\Models\Incident;

/**
 * Daily (Sprint 18.0): problems quiet for 7 days are resolved (if they come back they reopen and alert again);
 * daily counts older than 90 days and incidents closed for a year are removed.
 */
final class SweepCommand extends Command
{
    public const QUIET_DAYS = 7;

    protected $signature = 'watchtower:sweep';

    protected $description = 'Resolve problems that have gone quiet and remove old Watchtower data';

    public function handle(): int
    {
        $resolved = Incident::query()->whereIn('status', Incident::OPEN)->where('ongoing', false)->where('last_seen_at', '<', now()->subDays(self::QUIET_DAYS))
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
        DB::table('watchtower_incident_days')->where('day', '<', now()->subDays(90)->toDateString())->delete();
        $old = Incident::query()->whereIn('status', ['resolved', 'fixed', 'ignored'])->where('last_seen_at', '<', now()->subYear())->pluck('id')->all();
        foreach (array_chunk($old, 500) as $ids) {
            DB::table('watchtower_incident_businesses')->whereIn('incident_id', $ids)->delete();
            DB::table('watchtower_incident_days')->whereIn('incident_id', $ids)->delete();
            Incident::query()->whereIn('id', $ids)->delete();
        }
        $this->info("Resolved {$resolved} quiet problem(s); removed ".count($old).' old one(s).');

        return self::SUCCESS;
    }
}
