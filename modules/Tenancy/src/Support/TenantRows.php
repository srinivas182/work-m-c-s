<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One business's rows in a shard database (Sprint 18.4a): copy them to another database, compare two copies, or delete
 * them — table by table, parents before children (foreign keys always point at rows that exist). The basis of moving a
 * business between shards, restoring one business from a backup, and permanently removing an archived demo.
 */
final class TenantRows
{
    public const CHUNK = 1000;

    /** @return list<string> tables with a tenant_id, parents first */
    public function tables(string $connection): array
    {
        $schema = Schema::connection($connection);
        $db = DB::connection($connection);
        $listing = $db->getDriverName() === 'sqlite' ? $schema->getTableListing() : $schema->getTableListing($db->getDatabaseName(), false);
        $tables = array_values(array_filter(array_map(fn ($t) => str_contains($t, '.') ? substr($t, strpos($t, '.') + 1) : $t, $listing),
            fn ($t) => $t !== 'migrations' && ! str_starts_with($t, 'sqlite_') && $schema->hasColumn($t, 'tenant_id')));
        $parents = [];
        foreach ($tables as $t) {
            $parents[$t] = array_values(array_filter(array_map(fn ($fk) => (string) $fk['foreign_table'], $schema->getForeignKeys($t)), fn ($p) => $p !== $t && in_array($p, $tables, true)));
        }
        $out = [];
        $visit = function (string $t, array $path) use (&$visit, &$out, $parents): void {
            if (in_array($t, $out, true) || in_array($t, $path, true)) {
                return;
            }
            foreach ($parents[$t] as $p) {
                $visit($p, [...$path, $t]);
            }
            $out[] = $t;
        };
        foreach ($tables as $t) {
            $visit($t, []);
        }

        return $out;
    }

    /** @return array<string, int> rows per table for this business */
    public function counts(string $tenantId, string $connection): array
    {
        $db = DB::connection($connection);

        return collect($this->tables($connection))->mapWithKeys(fn ($t) => [$t => $db->table($t)->where('tenant_id', $tenantId)->count()])->filter()->all();
    }

    /**
     * Copy every row of the business from one database to another (which must hold none of its rows yet).
     *
     * @return array<string, int> rows copied per table
     */
    public function copy(string $tenantId, string $from, string $to): array
    {
        if ($this->counts($tenantId, $to) !== []) {
            throw new \DomainException('The destination already holds rows of this business — nothing was copied.');
        }
        $source = DB::connection($from);
        $target = DB::connection($to);
        $targetTables = $this->tables($to);
        $copied = [];
        // Foreign keys are checked by the comparison afterwards; rows within one table may point at each other in any order.
        Schema::connection($to)->withoutForeignKeyConstraints(function () use ($tenantId, $from, $to, $source, $target, $targetTables, &$copied): void {
            $this->copyTables($tenantId, $from, $to, $source, $target, $targetTables, $copied);
        });

        return array_filter($copied);
    }

    /**
     * @param  list<string>  $targetTables
     * @param  array<string, int>  $copied
     */
    private function copyTables(string $tenantId, string $from, string $to, Connection $source, Connection $target, array $targetTables, array &$copied): void
    {
        foreach ($this->tables($from) as $table) {
            if (! in_array($table, $targetTables, true)) {
                throw new \DomainException("The destination has no table {$table} — migrate it first.");
            }
            $key = $this->key($from, $table);
            $columns = array_values(array_intersect(Schema::connection($from)->getColumnListing($table), Schema::connection($to)->getColumnListing($table)));
            $copied[$table] = 0;
            $source->table($table)->where('tenant_id', $tenantId)->orderBy($key)->select($columns)->chunk(self::CHUNK, function ($rows) use ($target, $table, &$copied): void {
                $target->table($table)->insert($rows->map(fn ($r) => (array) $r)->all());
                $copied[$table] += $rows->count();
            });
        }
    }

    /**
     * A fingerprint of every row of the business, per table — two databases holding the same rows give the same one.
     *
     * @return array<string, string>
     */
    public function checksum(string $tenantId, string $connection): array
    {
        $db = DB::connection($connection);
        $out = [];
        foreach ($this->tables($connection) as $table) {
            $hash = hash_init('sha256');
            $n = 0;
            $db->table($table)->where('tenant_id', $tenantId)->orderBy($this->key($connection, $table))->chunk(self::CHUNK, function ($rows) use ($hash, &$n): void {
                foreach ($rows as $r) {
                    $row = array_map(fn ($v) => $v === null ? null : (string) $v, (array) $r); // the same text whichever database it came from
                    ksort($row);
                    hash_update($hash, (string) json_encode($row));
                    $n++;
                }
            });
            if ($n > 0) {
                $out[$table] = $n.':'.hash_final($hash);
            }
        }

        return $out;
    }

    /** Delete every row of the business, children first. Returns rows deleted. */
    public function delete(string $tenantId, string $connection): int
    {
        $db = DB::connection($connection);
        $n = 0;
        foreach (array_reverse($this->tables($connection)) as $table) {
            $n += $db->table($table)->where('tenant_id', $tenantId)->delete();
        }

        return $n;
    }

    private function key(string $connection, string $table): string
    {
        $primary = collect(Schema::connection($connection)->getIndexes($table))->firstWhere('primary', true);

        return (string) ($primary['columns'][0] ?? Schema::connection($connection)->getColumnListing($table)[0]);
    }
}
