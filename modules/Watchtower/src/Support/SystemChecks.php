<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Health\Contracts\HealthCheck;
use Wurk\Platform\Queues;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Every minute (Sprint 18.0): is each part of the platform working? The databases (central and every shard), cache,
 * file storage, the queues (not backed up), and every scheduled task (ran when it should have). A failure opens an
 * incident; a recovery closes it and says so.
 */
final readonly class SystemChecks
{
    /** The oldest waiting job may be this old before the queue counts as backed up. */
    public const QUEUE_MAX_AGE_SECONDS = 600;

    public const QUEUE_MAX_SIZE = 5000;

    /** A scheduled task this late counts as missed (allows for slow servers and onOneServer hand-offs). */
    public const SCHEDULE_GRACE_MINUTES = 10;

    public function __construct(private Container $app, private Recorder $recorder, private Alerts $alerts) {}

    /** @return array<string, array{ok: bool, message: string|null}> */
    public function run(): array
    {
        $results = [];
        foreach ($this->checks() as $name => $check) {
            $start = hrtime(true);
            try {
                $message = $check();
            } catch (Throwable $e) {
                $message = Mask::text($e->getMessage(), 200) ?: 'Failed';
            }
            $ok = $message === null;
            $this->store($name, $ok, $message, (int) round((hrtime(true) - $start) / 1e6));
            $results[$name] = ['ok' => $ok, 'message' => $message];
            if ($ok) {
                $cleared = $this->recorder->clear('check', $name);
                if ($cleared !== null) {
                    $this->alerts->recovered($cleared);
                }
            } else {
                $this->recorder->record(new Signal('check', $name, $this->label($name).': '.$message, severity: str_starts_with($name, 'queue:') || $name === 'backups' ? 'high' : 'critical',
                    message: $message, location: 'system check '.$name, surface: 'system', ongoing: true));
            }
        }
        $this->missedTasks();

        return $results;
    }

    /** @return array<string, callable(): ?string> null = fine, otherwise what's wrong */
    private function checks(): array
    {
        $checks = [];
        foreach ($this->app->tagged(HealthCheck::TAG) as $check) {
            /** @var HealthCheck $check */
            $checks[$check->name()] = function () use ($check): ?string {
                $r = $check->run();

                return $r->ok ? null : ($r->message ?? 'Failed');
            };
        }
        foreach ($this->app->make(TenantDirectory::class)->shardConnections() as $connection) {
            $checks['shard:'.$connection] = function () use ($connection): ?string {
                DB::connection($connection)->select('select 1');

                return null;
            };
        }
        $checks['storage'] = function (): ?string {
            $disk = Storage::disk((string) config('filesystems.default'));
            $path = 'watchtower/probe-'.Str::random(8).'.txt';
            $disk->put($path, 'ok');
            $back = $disk->get($path);
            $disk->delete($path);

            return $back === 'ok' ? null : 'Files can’t be written and read back';
        };
        if ((array) config('wurk.watchtower.backup_targets', []) !== []) {
            $checks['backups'] = fn (): ?string => Backups::stale(); // 18.4c
        }
        foreach ($this->queues() as $queue) {
            $checks['queue:'.$queue] = fn (): ?string => $this->backlog($queue);
        }

        return $checks;
    }

    /** @return list<string> */
    private function queues(): array
    {
        $connection = (string) config('queue.default');
        if ($connection === 'sync' || $connection === 'null') {
            return [];
        }

        return array_values(array_unique([...Queues::ALL, ...(array) config('wurk.watchtower.queues', [])])); // critical, default, bulk (18.3a)
    }

    private function backlog(string $queue): ?string
    {
        $q = $this->app->make(QueueFactory::class)->connection();
        $size = $q->size($queue);
        $oldest = method_exists($q, 'creationTimeOfOldestPendingJob') ? $q->creationTimeOfOldestPendingJob($queue) : null;
        $age = is_int($oldest) ? now()->getTimestamp() - $oldest : 0;
        if ($age > self::QUEUE_MAX_AGE_SECONDS || $size > self::QUEUE_MAX_SIZE) {
            return "{$size} jobs waiting".($age > 0 ? ', the oldest for '.intdiv($age, 60).' minutes' : '').' — are the queue workers running?';
        }

        return null;
    }

    /** Scheduled tasks that have run before but missed their latest time. */
    private function missedTasks(): void
    {
        $beats = DB::table('watchtower_heartbeats')->get()->keyBy('task');
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            $task = Heartbeats::task($event);
            $beat = $beats[$task] ?? null;
            if ($beat === null || $beat->last_started_at === null) {
                continue; // never seen yet (a new task, or a fresh install): nothing to compare
            }
            try {
                $tz = $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : (string) ($event->timezone ?: config('app.timezone'));
                $due = CarbonImmutable::instance((new CronExpression($event->expression))->getPreviousRunDate(now($tz)->toDateTimeImmutable(), 0, true));
            } catch (Throwable) {
                continue;
            }
            $last = CarbonImmutable::parse((string) $beat->last_started_at, (string) config('app.timezone'));
            $late = $due->addMinutes(self::SCHEDULE_GRACE_MINUTES);
            $group = 'missed:'.$task;
            if ($last->lessThan($due->subMinute()) && now()->greaterThan($late)) {
                $this->recorder->record(new Signal('scheduler', $group, "Scheduled task “{$task}” didn’t run at ".$due->setTimezone('Africa/Johannesburg')->format('H:i').' SAST',
                    severity: 'high', message: 'Last ran '.$last->setTimezone('Africa/Johannesburg')->format('j M H:i').' SAST. Is the scheduler running on one server?',
                    location: "command {$task}", surface: 'console', ongoing: true));
            } elseif (! $last->lessThan($due->subMinute())) {
                $cleared = $this->recorder->clear('scheduler', $group);
                if ($cleared !== null) {
                    $this->alerts->recovered($cleared);
                }
            }
        }
    }

    private function store(string $name, bool $ok, ?string $message, int $ms): void
    {
        $was = DB::table('watchtower_checks')->where('name', $name)->first();
        DB::table('watchtower_checks')->updateOrInsert(['name' => $name], [
            'ok' => $ok, 'message' => $message === null ? null : mb_substr($message, 0, 255), 'duration_ms' => $ms, 'checked_at' => now(),
            'failing_since' => $ok ? null : ($was !== null && ! $was->ok ? $was->failing_since : now()),
        ]);
    }

    private function label(string $name): string
    {
        return match (true) {
            $name === 'database' => 'Central database',
            $name === 'cache' => 'Cache (Redis)',
            $name === 'storage' => 'File storage',
            $name === 'backups' => 'Backups overdue',
            str_starts_with($name, 'shard:') => 'Database shard '.substr($name, 6),
            str_starts_with($name, 'queue:') => 'Queue “'.substr($name, 6).'” backed up',
            default => Str::headline($name),
        };
    }

    /**
     * For the Admin page: the latest result of every check.
     *
     * @return list<array{name: string, ok: bool, message: string|null, checked_at: string, failing_since: string|null}>
     */
    public static function latest(): array
    {
        return array_values(DB::table('watchtower_checks')->orderBy('name')->get()->map(fn ($c) => ['name' => (string) $c->name, 'ok' => (bool) $c->ok, 'message' => $c->message === null ? null : (string) $c->message,
            'checked_at' => (string) $c->checked_at, 'failing_since' => $c->failing_since === null ? null : (string) $c->failing_since])->all());
    }
}
