<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Sharding;

use Illuminate\Contracts\Config\Repository;
use Wurk\Tenancy\Models\Shard;

/**
 * Registers one database connection per shard (not per tenant), so many tenants
 * on the same shard share a connection pool.
 */
final readonly class ShardConnections
{
    public function __construct(private Repository $config) {}

    public static function nameFor(string $shardId): string
    {
        return 'shard_'.strtolower($shardId);
    }

    /** Ensures the shard's connection is registered and returns its name. */
    public function ensure(string $shardId): string
    {
        $name = self::nameFor($shardId);

        if (! $this->config->has("database.connections.{$name}")) {
            $this->register(Shard::query()->findOrFail($shardId));
        }

        return $name;
    }

    public function register(Shard $shard): string
    {
        $name = self::nameFor($shard->id);
        $this->config->set("database.connections.{$name}", $this->settingsFor($shard->connection));

        return $name;
    }

    /**
     * Shard settings merged over the platform's base settings for the same driver
     * (charset, collation, options), so only what differs is stored per shard.
     *
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    public function settingsFor(array $connection): array
    {
        $driver = (string) ($connection['driver'] ?? 'mysql');
        $base = (array) $this->config->get("database.connections.{$driver}", []);

        return array_merge($base, ['url' => null], $connection);
    }
}
