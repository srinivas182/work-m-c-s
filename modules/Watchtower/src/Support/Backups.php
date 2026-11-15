<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sprint 18.4c: what Watchtower knows about backups and restore drills. Backups are taken outside WURK (the managed
 * database's snapshots and point-in-time log, object-storage versioning — Sprint 17); each backup job reports in with
 * `system:backup-taken`, so a backup that stops arriving becomes a problem like any other.
 */
final class Backups
{
    public const KINDS = ['full', 'incremental', 'snapshot', 'files'];

    public const SCOPES = ['shard', 'business', 'everything'];

    public static function record(string $target, string $kind, CarbonImmutable $at, ?int $size, ?string $location, ?string $by): void
    {
        DB::table('watchtower_backups')->insert(['id' => strtolower((string) Str::ulid()), 'target' => mb_substr($target, 0, 64), 'kind' => $kind,
            'taken_at' => $at->setTimezone(self::tz()), 'size_bytes' => $size, 'location' => $location === null ? null : mb_substr($location, 0, 255),
            'recorded_by' => $by === null ? null : mb_substr($by, 0, 120), 'created_at' => now()]);
    }

    /** @return array{restore_minutes: int, data_loss_minutes: int|null, passed: bool} */
    public static function recordDrill(string $scope, ?string $target, CarbonImmutable $failedAt, CarbonImmutable $restoredAt, ?CarbonImmutable $backupAt, ?string $notes, ?string $by): array
    {
        $restore = (int) max(0, ceil($failedAt->diffInSeconds($restoredAt) / 60));
        $loss = $backupAt === null ? null : (int) max(0, ceil($backupAt->diffInSeconds($failedAt) / 60));
        $passed = $restore <= (int) config('wurk.watchtower.rto_minutes', 240) && ($loss === null || $loss <= (int) config('wurk.watchtower.rpo_minutes', 15));
        DB::table('watchtower_drills')->insert(['id' => strtolower((string) Str::ulid()), 'scope' => $scope, 'target' => $target, 'backup_at' => $backupAt?->setTimezone(self::tz()),
            'failed_at' => $failedAt->setTimezone(self::tz()), 'restored_at' => $restoredAt->setTimezone(self::tz()), 'restore_minutes' => $restore, 'data_loss_minutes' => $loss, 'passed' => $passed,
            'notes' => $notes, 'recorded_by' => $by, 'created_at' => now()]);

        return ['restore_minutes' => $restore, 'data_loss_minutes' => $loss, 'passed' => $passed];
    }

    /** Times are stored in WURK's time zone, like every other date WURK writes. */
    public static function tz(): string
    {
        return (string) config('app.timezone', 'UTC');
    }

    /** Null when every expected target has a recent backup, otherwise which haven't. */
    public static function stale(): ?string
    {
        $latest = self::latestTimes();
        $max = (int) config('wurk.watchtower.backup_max_age_hours', 26);
        $late = [];
        foreach ((array) config('wurk.watchtower.backup_targets', []) as $target) {
            $at = $latest[$target] ?? null;
            if ($at === null) {
                $late[] = "{$target} (never)";
            } elseif ($at->lessThan(now()->subHours($max))) {
                $late[] = "{$target} (".(int) $at->diffInHours(now()).' hours ago)';
            }
        }

        return $late === [] ? null : 'No backup within '.$max.' hours: '.implode(', ', $late).'. Are the backup jobs running and reporting in?';
    }

    /** @return array<string, CarbonImmutable> */
    private static function latestTimes(): array
    {
        return DB::table('watchtower_backups')->selectRaw('target, max(taken_at) as at')->groupBy('target')->pluck('at', 'target')
            ->map(fn ($at) => CarbonImmutable::parse((string) $at, self::tz()))->all();
    }

    /**
     * For Admin → System: the latest backup of every target seen or expected.
     *
     * @return list<array{target: string, kind: string|null, taken_at: string|null, size_bytes: int|null, location: string|null, late: bool, expected: bool}>
     */
    public static function latest(): array
    {
        $expected = (array) config('wurk.watchtower.backup_targets', []);
        $max = (int) config('wurk.watchtower.backup_max_age_hours', 26);
        $rows = DB::table('watchtower_backups as b')
            ->joinSub(DB::table('watchtower_backups')->selectRaw('target, max(taken_at) as at')->groupBy('target'), 'm', fn ($j) => $j->on('m.target', '=', 'b.target')->on('m.at', '=', 'b.taken_at'))
            ->get(['b.target', 'b.kind', 'b.taken_at', 'b.size_bytes', 'b.location'])->keyBy('target');
        $targets = array_values(array_unique([...$expected, ...$rows->keys()->map(fn ($t) => (string) $t)->all()]));
        sort($targets);

        return array_map(function (string $t) use ($rows, $expected, $max) {
            $r = $rows[$t] ?? null;
            $at = $r === null ? null : CarbonImmutable::parse((string) $r->taken_at, self::tz());

            return ['target' => $t, 'kind' => $r === null ? null : (string) $r->kind, 'taken_at' => $at?->toIso8601String(),
                'size_bytes' => $r?->size_bytes === null ? null : (int) $r->size_bytes, 'location' => $r?->location === null ? null : (string) $r->location,
                'late' => in_array($t, $expected, true) && ($at === null || $at->lessThan(now()->subHours($max))), 'expected' => in_array($t, $expected, true)];
        }, $targets);
    }

    /**
     * @return list<array{scope: string, target: string|null, backup_at: string|null, failed_at: string, restored_at: string, restore_minutes: int, data_loss_minutes: int|null, passed: bool, notes: string|null}>
     */
    public static function drills(int $limit = 5): array
    {
        return array_values(DB::table('watchtower_drills')->orderByDesc('restored_at')->limit($limit)->get()->map(fn ($d) => [
            'scope' => (string) $d->scope, 'target' => $d->target === null ? null : (string) $d->target,
            'backup_at' => $d->backup_at === null ? null : CarbonImmutable::parse((string) $d->backup_at, self::tz())->toIso8601String(),
            'failed_at' => CarbonImmutable::parse((string) $d->failed_at, self::tz())->toIso8601String(), 'restored_at' => CarbonImmutable::parse((string) $d->restored_at, self::tz())->toIso8601String(),
            'restore_minutes' => (int) $d->restore_minutes, 'data_loss_minutes' => $d->data_loss_minutes === null ? null : (int) $d->data_loss_minutes,
            'passed' => (bool) $d->passed, 'notes' => $d->notes === null ? null : (string) $d->notes,
        ])->all());
    }
}
