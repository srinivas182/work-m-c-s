<?php

declare(strict_types=1);

namespace Wurk\Plans\Support;

use Wurk\Platform\Modules\ModuleRegistry;

/**
 * Sellable modules: the planned catalogue (config/wurk.php "modules") plus any
 * installed module whose manifest declares an entitlement key.
 */
final readonly class ModuleCatalogue
{
    public function __construct(private ModuleRegistry $modules) {}

    /** @return array<string, array{name: string, installed: bool}> */
    public function all(): array
    {
        $catalogue = [];
        foreach ((array) config('wurk.modules', []) as $key => $module) {
            $catalogue[(string) $key] = ['name' => (string) ($module['name'] ?? $key), 'installed' => false];
        }

        foreach ($this->modules->all() as $manifest) {
            if ($manifest->entitlement !== null) {
                $catalogue[$manifest->entitlement] = ['name' => $catalogue[$manifest->entitlement]['name'] ?? $manifest->name, 'installed' => true];
            }
        }

        return $catalogue;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
