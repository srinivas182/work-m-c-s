<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Watchtower\Mail\ReleaseMail;

/**
 * Releases (Sprint 17.2). After a deploy migrates, `run()` does the version-specific steps that haven't run in this
 * environment yet (config/release.php), oldest version first, stopping at the first that fails so the deploy stops
 * too. `finished()` records how the deploy went and tells HQ (the Watchtower recipients).
 */
final readonly class Releases
{
    public const STATUSES = ['live', 'rolled_back', 'failed'];

    public function __construct(private AuditLog $audit, private Alerts $alerts) {}

    /** @return list<array{key: string, version: string, command: string}> the steps still to run here, in order */
    public function pending(): array
    {
        $once = (array) config('release.once', []);
        uksort($once, fn (int|string $a, int|string $b): int => version_compare((string) $a, (string) $b));
        $steps = [];
        foreach ($once as $version => $commands) {
            foreach ((array) $commands as $command) {
                $steps[] = ['key' => $version.' '.$command, 'version' => (string) $version, 'command' => (string) $command];
            }
        }
        $done = DB::table('release_tasks')->whereIn('key', array_column($steps, 'key'))->pluck('key')->all();
        $pending = array_values(array_filter($steps, fn (array $s) => ! in_array($s['key'], $done, true)));
        foreach ((array) config('release.every', []) as $command) {
            $pending[] = ['key' => 'every '.$command, 'version' => 'every', 'command' => (string) $command];
        }

        return $pending;
    }

    /**
     * Runs the pending steps. Returns [steps run, the step that failed or null].
     *
     * @param  callable(string): void  $say
     * @return array{int, string|null}
     */
    public function run(callable $say): array
    {
        $ran = 0;
        foreach ($this->pending() as $step) {
            $say($step['command']);
            $started = hrtime(true);
            try {
                $code = Artisan::call($step['command'], ['--no-interaction' => true]);
            } catch (Throwable $e) {
                report($e);
                $code = 1;
            }
            if ($code !== 0) {
                $this->audit->record('release.task_failed', 'release', $step['key'], ['command' => $step['command']]);

                return [$ran, $step['command']];
            }
            if ($step['version'] !== 'every') {
                DB::table('release_tasks')->insert(['key' => $step['key'], 'version' => $step['version'], 'command' => $step['command'],
                    'ran_at' => now(), 'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000)]);
            }
            $ran++;
        }

        return [$ran, null];
    }

    /** A deploy is over: record it, and tell HQ. */
    public function finished(string $version, string $environment, string $status, ?string $note): void
    {
        DB::table('releases')->insert(['id' => (string) Str::ulid(), 'version' => mb_substr($version, 0, 40), 'environment' => mb_substr($environment, 0, 20),
            'status' => $status, 'commit' => Release::commit(), 'note' => $note !== null ? mb_substr($note, 0, 500) : null, 'finished_at' => now()]);
        $this->audit->record('release.'.$status, 'release', $version, ['environment' => $environment]);
        $to = $this->alerts->recipients();
        if ($to !== []) {
            try {
                Mail::to($to)->send(new ReleaseMail($version, $environment, $status, $note));
            } catch (Throwable $e) {
                report($e); // the release itself stands
            }
        }
    }

    /** @return list<array{version: string, environment: string, status: string, note: string|null, finished_at: string}> */
    public function recent(int $limit = 10): array
    {
        return array_values(array_map(fn ($r) => ['version' => (string) $r->version, 'environment' => (string) $r->environment, 'status' => (string) $r->status,
            'note' => $r->note, 'finished_at' => (string) $r->finished_at], DB::table('releases')->orderByDesc('finished_at')->orderByDesc('id')->limit($limit)->get()->all()));
    }
}
