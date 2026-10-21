<?php

declare(strict_types=1);

namespace Wurk\Access\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Permissions\PermissionRegistry;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Run on every deploy. System roles live in code, so the only thing to do is
 * remove permissions that modules no longer declare from custom roles — on the
 * central database and on every shard, without entering each tenant.
 */
final class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync {--list : Show every declared permission}';

    protected $description = 'Validate role definitions and prune undeclared permissions from custom roles';

    public function handle(PermissionRegistry $registry): int
    {
        $registry->flush();

        foreach (SystemRoles::DEFINITIONS as $key => $role) {
            $unknown = array_diff($role['permissions'], ['*', ...$registry->keys($role['scope'])]);
            if ($unknown !== []) {
                $this->components->error("System role [{$key}] references undeclared permissions: ".implode(', ', $unknown));

                return self::FAILURE;
            }
        }

        $pruned = 0;
        foreach (PlatformRole::query()->get() as $role) {
            $kept = array_values(array_intersect($role->permissions, $registry->keys(Permission::PLATFORM)));
            if ($kept !== $role->permissions) {
                $role->forceFill(['permissions' => $kept])->save();
                $pruned++;
            }
        }

        $business = $registry->keys(Permission::BUSINESS);
        foreach ($this->shardConnections() as $connection) {
            foreach (DB::connection($connection)->table('roles')->get(['id', 'permissions']) as $row) {
                $current = json_decode((string) $row->permissions, true) ?: [];
                $kept = array_values(array_intersect($current, $business));
                if ($kept !== $current) {
                    DB::connection($connection)->table('roles')->where('id', $row->id)->update(['permissions' => json_encode($kept)]);
                    $pruned++;
                }
            }
        }

        if ($this->option('list')) {
            $this->table(['Permission', 'Scope', 'Module', 'Label'], array_map(
                fn (Permission $p) => [$p->key, $p->scope, $p->module, $p->label],
                array_values($registry->all()),
            ));
        }

        $this->components->info(count($registry->all()).' permissions declared; '.$pruned.' custom role(s) updated.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function shardConnections(): array
    {
        return app(TenantDirectory::class)->shardConnections();
    }
}
