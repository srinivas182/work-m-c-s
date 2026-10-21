<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Actions;

use Wurk\Tenancy\Models\Shard;

final readonly class RegisterShard
{
    public function __construct(private MigrateShard $migrate) {}

    /**
     * Registers a shard and brings its schema up to date.
     *
     * @param  array<string, mixed>  $connection
     */
    public function __invoke(string $name, array $connection, ?int $capacity = null, bool $fresh = false): Shard
    {
        if (($connection['driver'] ?? null) === 'sqlite') {
            $database = (string) ($connection['database'] ?? '');
            if ($database !== ':memory:' && ! file_exists($database)) {
                touch($database);
            }
        }

        $shard = Shard::query()->create([
            'name' => $name,
            'connection' => $connection,
            'status' => Shard::STATUS_ACTIVE,
            'capacity' => $capacity ?? (int) config('wurk.tenancy.shard_capacity', 5000),
        ]);

        ($this->migrate)($shard, fresh: $fresh);

        return $shard;
    }
}
