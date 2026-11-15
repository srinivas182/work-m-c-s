<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/** For Admin → System (Sprint 18.4c): the shards' state and recent business moves/restores, without reaching into Tenancy's models. */
interface ShardStatus
{
    /**
     * @return list<array{name: string, status: string, businesses: int, capacity: int, schema_version: int, expected_version: int, migrated_at: string|null}>
     */
    public function shards(): array;

    /**
     * @return list<array{business: string, kind: string, status: string, from: string|null, to: string, rows: int, error: string|null, started_at: string, clear_after: string|null}>
     */
    public function recentMoves(int $limit = 10): array;
}
