<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * "Needs you now" on Home (Sprint 26.3): one list across the modules of what's waiting on the owner — deposits not
 * paid, chats waiting, orders to pack, money owed, demo photos, credits — newest first, each with its action.
 * Sources run inside the business, for the signed-in person (they check their own permissions).
 *
 * An item: ['id' => string, 'kind' => string, 'title' => string, 'detail' => string, 'at' => ISO 8601,
 *   'url' => string|null, 'actions' => list<['label' => string, 'url' => string, 'data' => array, 'tone' => 'primary'|'secondary']>]
 */
interface NeedsYouNow
{
    /** @param \Closure(): iterable<array<string, mixed>> $source */
    public function register(string $key, \Closure $source): void;

    /** @return list<array<string, mixed>> newest first, at most `$limit` */
    public function all(int $limit = 30): array;
}
