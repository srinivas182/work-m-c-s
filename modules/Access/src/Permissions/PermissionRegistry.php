<?php

declare(strict_types=1);

namespace Wurk\Access\Permissions;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Wurk\Platform\Modules\ModuleServiceProvider;

/**
 * Every permission in the platform, collected from module declarations.
 *
 * A module declares permissions in its provider's permissions():
 *   'bookings.manage'                                         (business scope)
 *   ['key' => 'tenants.manage', 'label' => '…', 'scope' => 'platform']
 */
final class PermissionRegistry
{
    /** @var array<string, Permission>|null */
    private ?array $permissions = null;

    public function __construct(private readonly Application $app) {}

    /** @return array<string, Permission> */
    public function all(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        $found = [];
        foreach ($this->app->getProviders(ModuleServiceProvider::class) as $provider) {
            if (! $provider instanceof ModuleServiceProvider) {
                continue;
            }
            $module = class_basename($provider);
            foreach ($provider->permissions() as $declaration) {
                $permission = self::normalise($declaration, $module);
                if (isset($found[$permission->key])) {
                    throw new InvalidArgumentException("Permission [{$permission->key}] is declared twice.");
                }
                $found[$permission->key] = $permission;
            }
        }
        ksort($found);

        return $this->permissions = $found;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?Permission
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(string $scope): array
    {
        return array_keys(array_filter($this->all(), fn (Permission $p) => $p->scope === $scope));
    }

    public function flush(): void
    {
        $this->permissions = null;
    }

    /** @param  string|array<string, string>  $declaration */
    private static function normalise(string|array $declaration, string $module): Permission
    {
        $declaration = is_string($declaration) ? ['key' => $declaration] : $declaration;
        $key = (string) ($declaration['key'] ?? '');

        if (preg_match('/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/', $key) !== 1) {
            throw new InvalidArgumentException("Invalid permission key [{$key}] in {$module}.");
        }

        $scope = $declaration['scope'] ?? Permission::BUSINESS;
        if (! in_array($scope, [Permission::PLATFORM, Permission::BUSINESS], true)) {
            throw new InvalidArgumentException("Permission [{$key}] has an invalid scope.");
        }

        $label = $declaration['label'] ?? ucfirst(str_replace(['.', '-'], ' ', $key));

        return new Permission($key, $label, $scope, $module);
    }
}
