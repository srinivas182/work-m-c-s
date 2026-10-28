<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Tenancy\Actions\CreateTenant;
use Wurk\Tenancy\Actions\RegisterShard;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;

/**
 * Creates real shards for tests.
 *
 * SQLite (default): each shard is a fresh temporary database file.
 * MySQL (TEST_SHARD_DRIVER=mysql): uses the databases listed in
 * TEST_SHARD_DATABASES and rebuilds them for every test.
 */
trait InteractsWithShards
{
    /** @return list<Shard> */
    protected function createShards(int $count = 2, int $capacity = 5000): array
    {
        $driver = (string) env('TEST_SHARD_DRIVER', 'sqlite');
        $mysqlDatabases = array_values(array_filter(explode(',', (string) env('TEST_SHARD_DATABASES', 'wurk_shard_1,wurk_shard_2'))));
        $shards = [];

        for ($i = 1; $i <= $count; $i++) {
            $connection = $driver === 'mysql'
                ? [
                    'driver' => 'mysql',
                    'host' => env('DB_HOST', '127.0.0.1'),
                    'port' => env('DB_PORT', '3306'),
                    'database' => $mysqlDatabases[$i - 1] ?? "wurk_shard_{$i}",
                    'username' => env('DB_USERNAME', 'root'),
                    'password' => env('DB_PASSWORD', ''),
                ]
                : [
                    'driver' => 'sqlite',
                    'database' => $this->temporaryShardFile($i),
                    'foreign_key_constraints' => true,
                ];

            $shards[] = app(RegisterShard::class)("test-shard-{$i}", $connection, $capacity, fresh: true);
        }

        return $shards;
    }

    protected function createTenant(string $handle, ?Shard $shard = null, ?string $name = null): Tenant
    {
        return app(CreateTenant::class)($name ?? ucfirst($handle), $handle, shard: $shard);
    }

    /**
     * A fresh SQLite file for a test shard, deleted when the test's application shuts down. (Leftover shard
     * files once filled the disk: tens of thousands, 6 GB.)
     */
    private function temporaryShardFile(int $i): string
    {
        $placeholder = tempnam(sys_get_temp_dir(), "wurk-shard-{$i}-");
        @unlink($placeholder); // only its unique name is needed
        $file = $placeholder.'.sqlite';
        $this->beforeApplicationDestroyed(function () use ($file): void {
            DB::purge(); // close connections before removing the file
            foreach ([$file, $file.'-journal', $file.'-wal', $file.'-shm'] as $f) {
                @unlink($f);
            }
        });

        return $file;
    }
}
