<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * "Recently deleted" (Sprint 16.6, decision C): for 30 days, deleted things can be put back with one click; after
 * that they're gone for good. Each module registers what it can bring back. Everything runs in the business's context.
 */
interface RecentlyDeleted
{
    public const DAYS = 30;

    /**
     * @param  \Closure(\DateTimeInterface): array<int, array{id: string, name: string, deleted_at: \DateTimeInterface}>  $list  deleted since the date
     * @param  \Closure(string): string  $restore  puts one back; returns what to tell the person; throws CannotRestore with why not
     * @param  \Closure(\DateTimeInterface): int  $purge  removes for good what was deleted before the date; returns how many
     */
    public function register(string $type, string $label, string $permission, \Closure $list, \Closure $restore, \Closure $purge): void;

    /** @return array<string, array{label: string, permission: string, list: \Closure, restore: \Closure, purge: \Closure}> */
    public function types(): array;
}
