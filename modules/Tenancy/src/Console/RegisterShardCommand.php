<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Wurk\Tenancy\Actions\RegisterShard;
use Wurk\Tenancy\Models\Shard;

final class RegisterShardCommand extends Command
{
    protected $signature = 'shards:register
        {name : Unique shard name, e.g. za-shared-001}
        {--driver=mysql : mysql or sqlite}
        {--host=127.0.0.1}
        {--port=3306}
        {--database= : Database name (or file path for sqlite)}
        {--username=}
        {--password=}
        {--capacity= : Maximum tenants on this shard}
        {--fresh : Drop all tables first (never use on a shard with data)}
        {--if-missing : Do nothing if a shard with this name already exists}';

    protected $description = 'Register a shared tenant database and run tenant migrations on it';

    public function handle(RegisterShard $register): int
    {
        if ($this->option('if-missing') && Shard::query()->where('name', $this->argument('name'))->exists()) {
            $this->components->info('Shard ['.$this->argument('name').'] already registered.');

            return self::SUCCESS;
        }

        $driver = (string) $this->option('driver');
        $connection = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => (string) $this->option('database'), 'foreign_key_constraints' => true]
            : array_filter([
                'driver' => $driver,
                'host' => $this->option('host'),
                'port' => $this->option('port'),
                'database' => $this->option('database'),
                'username' => $this->option('username'),
                'password' => $this->option('password'),
            ], fn ($v) => $v !== null && $v !== '');

        $capacity = $this->option('capacity');
        $shard = $register((string) $this->argument('name'), $connection, is_numeric($capacity) ? (int) $capacity : null, (bool) $this->option('fresh'));

        $this->components->info("Shard [{$shard->name}] registered at schema version {$shard->schema_version}.");

        return self::SUCCESS;
    }
}
