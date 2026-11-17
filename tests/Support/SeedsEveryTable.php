<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sprint 18.2: puts one row in every table of a business's shard, filled from the schema alone — every text column
 * carries a marker ("BRAVO-SECRET…"), so if any of it shows up in another business's response, the test sees it.
 * Tables are filled parents first (foreign keys point at real rows).
 */
final class SeedsEveryTable
{
    public const MARKER = 'BRAVOSECRET';

    /** @var array<string, string|int> table => the seeded row's key */
    public array $ids = [];

    /** @var array<string, string> table => why it couldn't be filled */
    public array $skipped = [];

    /** @param bool $tenantTablesOnly the central database: only tables that belong to a business (and the rows they need) */
    public function __construct(private readonly string $connection, private readonly string $tenantId, private readonly bool $tenantTablesOnly = false) {}

    public function run(): self
    {
        $schema = Schema::connection($this->connection);
        $db = DB::connection($this->connection);
        $listing = $db->getDriverName() === 'sqlite' ? $schema->getTableListing() : $schema->getTableListing($db->getDatabaseName(), false); // this database only
        $tables = array_values(array_filter(array_map(fn ($t) => str_contains($t, '.') ? substr($t, strpos($t, '.') + 1) : $t, $listing),
            fn ($t) => $t !== 'migrations' && ! str_starts_with($t, 'sqlite_')));
        $fks = [];
        foreach ($tables as $t) {
            $fks[$t] = $schema->getForeignKeys($t);
        }
        foreach ($this->order($tables, $fks) as $table) {
            try {
                $this->ids[$table] = $this->insert($table, $schema->getColumns($table), $fks[$table], $schema->getIndexes($table));
            } catch (Throwable $e) {
                // One row per business by design (its subscription): use the one it already has.
                $existing = $schema->hasColumn($table, 'tenant_id') ? DB::connection($this->connection)->table($table)->where('tenant_id', $this->tenantId)->first() : null;
                if ($existing !== null) {
                    $key = (collect($schema->getIndexes($table))->firstWhere('primary', true)['columns'] ?? ['id'])[0];
                    $this->ids[$table] = $existing->{$key};
                } else {
                    $this->skipped[$table] = Str::limit($e->getMessage(), 160);
                }
            }
        }

        return $this;
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, list<array<string, mixed>>>  $fks
     * @return list<string> parents before children
     */
    private function order(array $tables, array $fks): array
    {
        $done = [];
        $out = [];
        $visit = function (string $t, array $path) use (&$visit, &$done, &$out, $fks, $tables): void {
            if (isset($done[$t]) || in_array($t, $path, true) || ! in_array($t, $tables, true)) {
                return;
            }
            foreach ($fks[$t] as $fk) {
                if ($fk['foreign_table'] !== $t && $fk['columns'] !== ['tenant_id']) { // the business itself already exists
                    $visit((string) $fk['foreign_table'], [...$path, $t]);
                }
            }
            $done[$t] = true;
            $out[] = $t;
        };
        foreach ($tables as $t) {
            if (! $this->tenantTablesOnly || Schema::connection($this->connection)->hasColumn($t, 'tenant_id')) {
                $visit($t, []);
            }
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $fks
     * @param  list<array<string, mixed>>  $indexes
     */
    private function insert(string $table, array $columns, array $fks, array $indexes): string|int
    {
        $fkFor = [];
        foreach ($fks as $fk) {
            $fkFor[$fk['columns'][0]] = $fk['foreign_table'];
        }
        $primary = collect($indexes)->firstWhere('primary', true)['columns'] ?? ['id'];
        $row = [];
        foreach ($columns as $c) {
            $name = (string) $c['name'];
            $type = strtolower((string) $c['type_name']);
            if ($c['auto_increment']) {
                continue;
            }
            if ($name === 'tenant_id') {
                $row[$name] = $this->tenantId;
            } elseif (isset($fkFor[$name])) {
                $parent = $fkFor[$name];
                $row[$name] = $this->ids[$parent] ?? ($c['nullable'] ? null : throw new \RuntimeException("needs {$parent}"));
            } elseif (in_array($name, $primary, true) || ! $c['nullable'] && $c['default'] === null || $this->isText($type)) {
                $row[$name] = $this->value($table, $name, $type, (string) $c['type']);
            }
        }
        DB::connection($this->connection)->table($table)->insert($row);
        $key = $primary[0] ?? 'id';

        return $row[$key] ?? (int) DB::connection($this->connection)->table($table)->max($key);
    }

    /**
     * Sprint 18.3a (query budgets): $n more rows like the seeded one in $table — new keys, unique columns made unique.
     */
    public function copies(string $table, int $n): void
    {
        $schema = Schema::connection($this->connection);
        $db = DB::connection($this->connection);
        $indexes = $schema->getIndexes($table);
        $primary = collect($indexes)->firstWhere('primary', true)['columns'] ?? ['id'];
        $unique = collect($indexes)->filter(fn ($i) => $i['unique'] && ! $i['primary'])->flatMap(fn ($i) => $i['columns'])->unique()->all();
        $types = collect($schema->getColumns($table))->mapWithKeys(fn ($c) => [$c['name'] => strtolower((string) $c['type_name'])])->all();
        $row = (array) $db->table($table)->where($primary[0], $this->ids[$table])->first();
        for ($i = 1; $i <= $n; $i++) {
            $copy = $row;
            foreach ([...$primary, ...$unique] as $col) {
                if ($col === 'tenant_id' || ! array_key_exists($col, $copy)) {
                    continue;
                }
                $copy[$col] = str_contains($types[$col] ?? '', 'int') ? (int) $copy[$col] + $i * 1000 + 1 : (strlen((string) $copy[$col]) === 26 // an ID (key or unique): a new one
                    ? strtolower((string) Str::ulid()) : substr((string) $copy[$col], 0, 40).'-'.$i);
            }
            $db->table($table)->insert($copy);
        }
    }

    private function isText(string $type): bool
    {
        return in_array($type, ['varchar', 'text', 'longtext', 'mediumtext', 'char', 'string'], true);
    }

    private function value(string $table, string $name, string $type, string $full): mixed
    {
        if ($name === 'id' || str_ends_with($name, '_id')) {
            return strtolower((string) Str::ulid());
        }
        preg_match('/\((\d+)\)/', $full, $m);
        $len = isset($m[1]) ? (int) $m[1] : 255;

        return match (true) {
            str_contains($type, 'int') => 1,
            in_array($type, ['decimal', 'numeric', 'float', 'double', 'real'], true) => 1,
            in_array($type, ['bool', 'boolean', 'tinyint(1)'], true) => 0,
            $type === 'date' => now()->toDateString(),
            in_array($type, ['datetime', 'timestamp'], true) => now()->toDateTimeString(),
            $type === 'time' => '10:00:00',
            $type === 'json' => '{}',
            $type === 'enum' => $this->enumValue($full),
            default => substr(self::MARKER.$table.$name, 0, max(1, min($len, 60))),
        };
    }

    private function enumValue(string $full): string
    {
        preg_match("/'([^']*)'/", $full, $m);

        return $m[1] ?? 'x';
    }
}
