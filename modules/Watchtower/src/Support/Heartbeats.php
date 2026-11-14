<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scheduled tasks report in (Sprint 18.0): when each started and finished, and whether it worked — so a task that
 * fails, or stops running, is noticed. Watchtower's own every-minute check is the scheduler's pulse (/health/heartbeat).
 */
final readonly class Heartbeats
{
    public const PULSE = 'watchtower:check';

    public function __construct(private Recorder $recorder) {}

    /** "success:score" — the artisan command, without the PHP binary path. */
    public static function task(Event $event): string
    {
        $command = (string) $event->command;
        $task = $command !== '' ? trim((string) Str::after($command, "'artisan' ")) : (string) ($event->description ?: 'closure');

        return mb_substr($task, 0, 120);
    }

    public function starting(ScheduledTaskStarting $e): void
    {
        $this->beat($e->task, ['last_started_at' => now(), 'last_status' => 'running']);
    }

    public function finished(ScheduledTaskFinished $e): void
    {
        $failed = $e->task->exitCode !== null && $e->task->exitCode !== 0;
        $this->beat($e->task, ['last_finished_at' => now(), 'last_status' => $failed ? 'failed' : 'ok', 'last_runtime_ms' => (int) round($e->runtime * 1000)]);
        if ($failed) {
            $task = self::task($e->task);
            $this->recorder->record(new Signal('scheduler', 'failed:'.$task, "Scheduled task “{$task}” failed (exit code {$e->task->exitCode})", severity: 'high',
                location: "command {$task}", surface: 'console'));
        }
    }

    public function failed(ScheduledTaskFailed $e): void
    {
        $this->beat($e->task, ['last_finished_at' => now(), 'last_status' => 'failed']);
        $task = self::task($e->task);
        $this->recorder->record(Signal::fromThrowable($e->exception, 'scheduler', "command {$task}", 'console', severity: 'high'));
    }

    /** Is the scheduler alive? (its every-minute check ran in the last few minutes) */
    public static function pulse(): ?string
    {
        return DB::table('watchtower_heartbeats')->where('task', self::PULSE)->value('last_started_at');
    }

    /** @param array<string, mixed> $values */
    private function beat(Event $event, array $values): void
    {
        try {
            DB::table('watchtower_heartbeats')->updateOrInsert(['task' => self::task($event)], [
                ...$values, 'expression' => mb_substr($event->expression, 0, 60),
                'timezone' => $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : (is_string($event->timezone) ? $event->timezone : null),
            ]);
        } catch (Throwable) {
            // never let watching a task break the task
        }
    }
}
