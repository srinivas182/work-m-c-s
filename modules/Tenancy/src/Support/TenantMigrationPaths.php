<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Support;

use Wurk\Platform\Modules\ModuleRegistry;

/**
 * Shard (tenant) migrations live in modules/{Name}/database/migrations/tenant.
 * Central migrations stay in modules/{Name}/database/migrations.
 */
final readonly class TenantMigrationPaths
{
    public function __construct(private ModuleRegistry $modules) {}

    /** @return list<string> absolute paths, in module boot order */
    public function all(): array
    {
        $paths = [];

        foreach ($this->modules->all() as $module) {
            $path = $module->path.'/database/migrations/tenant';
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
