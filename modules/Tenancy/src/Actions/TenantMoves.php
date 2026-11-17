<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantMove;
use Wurk\Tenancy\Sharding\ShardConnections;
use Wurk\Tenancy\Support\TenantRows;

/**
 * Moving a business to another shard, and restoring one business from a backup (Sprint 18.4a).
 *
 * A move: pause the business (its pages answer "back in a few minutes"), copy every row to the new shard, compare the two
 * copies row by row, switch the business over, carry on. The old copy stays where it was for 7 days — switching back is
 * one command — then it's removed. If anything goes wrong, the partial copy is removed and the business stays where it was.
 */
final readonly class TenantMoves
{
    public const KEEP_DAYS = 7;

    /** Longest a business stays paused if the process dies mid-move. */
    public const PAUSE_SECONDS = 1800;

    public function __construct(private TenantRows $rows, private ShardConnections $connections) {}

    public static function paused(string $tenantId): bool
    {
        return Cache::has('tenancy:paused:'.$tenantId);
    }

    public function move(Tenant $tenant, Shard $to, ?string $by = null): TenantMove
    {
        if ($tenant->shard_id === $to->id) {
            throw new \DomainException('The business is already on that shard.');
        }
        $from = Shard::query()->findOrFail($tenant->shard_id);

        return $this->transfer($tenant, 'move', $from->id, $this->connections->register($from), $to, $by, keepOld: true);
    }

    /**
     * One business from a backup: copied from the backup database (a connection, e.g. "restore") to a shard. To another
     * shard, the business's current rows stay where they are for 7 days. To its own shard (replace), its current rows
     * are saved to a file first, then replaced.
     */
    public function restore(Tenant $tenant, string $backupConnection, ?Shard $to, bool $replace, ?string $by = null): TenantMove
    {
        if ($this->rows->counts($tenant->id, $backupConnection) === []) {
            throw new \DomainException('The backup holds nothing for this business.');
        }
        if ($to === null || $to->id === $tenant->shard_id) {
            if (! $replace) {
                throw new \DomainException('Restoring onto its own shard replaces what it has now — confirm with replace, or choose another shard.');
            }
            $own = Shard::query()->findOrFail($tenant->shard_id);
            $live = $this->connections->register($own);
            $this->pause($tenant->id);
            try {
                $saved = $this->saveCopy($tenant->id, $live);
                $this->rows->delete($tenant->id, $live);
            } catch (Throwable $e) {
                $this->resume($tenant->id);
                throw $e;
            }

            return $this->transfer($tenant, 'restore', $backupConnection, $backupConnection, $own, $by, keepOld: false, alreadyPaused: true, saved: $saved);
        }

        return $this->transfer($tenant, 'restore', $backupConnection, $backupConnection, $to, $by, keepOld: true);
    }

    /** Old copies past their 7 days: removed. Returns how many. */
    public function clearOld(): int
    {
        $n = 0;
        foreach (TenantMove::query()->where('status', 'done')->whereNotNull('from_shard_id')->where('clear_after', '<', now())->get() as $m) {
            $tenant = Tenant::query()->find($m->tenant_id);
            $from = Shard::query()->find($m->from_shard_id);
            if ($tenant !== null && $from !== null && $tenant->shard_id !== $from->id) { // never the copy it's using
                $this->rows->delete($tenant->id, $this->connections->register($from));
            }
            $m->forceFill(['status' => 'cleared'])->save();
            $n++;
        }

        return $n;
    }

    private function transfer(Tenant $tenant, string $kind, string $source, string $fromConnection, Shard $to, ?string $by, bool $keepOld, bool $alreadyPaused = false, ?string $saved = null): TenantMove
    {
        $previous = $tenant->shard_id;
        $move = TenantMove::query()->create(['tenant_id' => $tenant->id, 'kind' => $kind, 'source' => $source, 'from_shard_id' => $keepOld ? $previous : null,
            'to_shard_id' => $to->id, 'status' => 'copying', 'started_by' => $by, 'started_at' => now()]);
        $target = $this->connections->register($to);
        if (! $alreadyPaused) {
            $this->pause($tenant->id);
        }
        try {
            $copied = $this->rows->copy($tenant->id, $fromConnection, $target);
            if ($this->rows->checksum($tenant->id, $fromConnection) !== $this->rows->checksum($tenant->id, $target)) {
                throw new \RuntimeException('The copy doesn’t match the original — nothing was switched.');
            }
            DB::transaction(function () use ($tenant, $to, $previous): void {
                $tenant->forceFill(['shard_id' => $to->id])->save(); // the model clears its cached resolution
                if ($previous !== $to->id) {                          // keep shard capacity right for new businesses
                    Shard::query()->whereKey($previous)->where('tenant_count', '>', 0)->decrement('tenant_count');
                    Shard::query()->whereKey($to->id)->increment('tenant_count');
                }
            });
            $move->forceFill(['status' => 'done', 'rows' => $copied, 'finished_at' => now(), 'clear_after' => $keepOld && $previous !== $to->id ? now()->addDays(self::KEEP_DAYS) : null])->save();

            return $move;
        } catch (Throwable $e) {
            // Leave no half copy behind. A replace puts the business's own rows back from the file saved first.
            if ($tenant->refresh()->shard_id !== $to->id || ! $keepOld) {
                $this->rows->delete($tenant->id, $target);
            }
            if ($saved !== null) {
                $this->loadCopy($saved, $target);
            }
            $move->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now()])->save();
            throw $e;
        } finally {
            $this->resume($tenant->id);
        }
    }

    /** Before a restore replaces a business's rows: every row to a compressed file (one JSON line each). */
    private function saveCopy(string $tenantId, string $connection): string
    {
        $path = 'restore-safety/'.$tenantId.'-'.now()->format('Ymd-His').'.jsonl.gz';
        $lines = [];
        foreach ($this->rows->tables($connection) as $table) {
            DB::connection($connection)->table($table)->where('tenant_id', $tenantId)->orderBy(DB::raw('1'))->chunk(TenantRows::CHUNK, function ($rows) use ($table, &$lines): void {
                foreach ($rows as $r) {
                    $lines[] = (string) json_encode(['table' => $table, 'row' => (array) $r]);
                }
            });
        }
        Storage::disk('local')->put($path, (string) gzencode(implode("\n", $lines)));

        return $path;
    }

    /** Put rows saved by saveCopy() back. */
    public function loadCopy(string $path, string $connection): int
    {
        $n = 0;
        $byTable = [];
        foreach (explode("\n", (string) gzdecode((string) Storage::disk('local')->get($path))) as $line) {
            $d = json_decode($line, true);
            if (is_array($d) && is_string($d['table'] ?? null) && is_array($d['row'] ?? null)) {
                $byTable[$d['table']][] = $d['row'];
            }
        }
        Schema::connection($connection)->withoutForeignKeyConstraints(function () use ($byTable, $connection, &$n): void {
            foreach ($byTable as $table => $rows) {
                foreach (array_chunk($rows, TenantRows::CHUNK) as $chunk) {
                    DB::connection($connection)->table($table)->insert($chunk);
                    $n += count($chunk);
                }
            }
        });

        return $n;
    }

    private function pause(string $tenantId): void
    {
        Cache::put('tenancy:paused:'.$tenantId, true, self::PAUSE_SECONDS);
    }

    private function resume(string $tenantId): void
    {
        Cache::forget('tenancy:paused:'.$tenantId);
    }
}
