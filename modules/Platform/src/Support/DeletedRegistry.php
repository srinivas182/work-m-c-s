<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\RecentlyDeleted;

final class DeletedRegistry implements RecentlyDeleted
{
    /** @var array<string, array{label: string, permission: string, list: \Closure, restore: \Closure, purge: \Closure}> */
    private array $types = [];

    public function register(string $type, string $label, string $permission, \Closure $list, \Closure $restore, \Closure $purge): void
    {
        $this->types[$type] = ['label' => $label, 'permission' => $permission, 'list' => $list, 'restore' => $restore, 'purge' => $purge];
    }

    public function types(): array
    {
        return $this->types;
    }
}
