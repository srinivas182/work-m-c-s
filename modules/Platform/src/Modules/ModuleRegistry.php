<?php

declare(strict_types=1);

namespace Wurk\Platform\Modules;

use Wurk\Platform\Modules\Exceptions\ModuleDependencyException;

/**
 * Discovers installed modules and orders them so every module boots after its dependencies.
 *
 * Discovery reads modules/{Name}/module.json once per process (once per worker under Octane).
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleManifest>|null keyed by module key, in boot order */
    private ?array $modules = null;

    public function __construct(private readonly string $modulesPath) {}

    /**
     * @return array<string, ModuleManifest>
     */
    public function all(): array
    {
        return $this->modules ??= $this->discover();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function get(string $key): ?ModuleManifest
    {
        return $this->all()[$key] ?? null;
    }

    public function path(): string
    {
        return $this->modulesPath;
    }

    /**
     * @return array<string, ModuleManifest>
     */
    private function discover(): array
    {
        $found = [];

        foreach (glob($this->modulesPath.'/*/module.json') ?: [] as $file) {
            $manifest = ModuleManifest::fromFile($file);

            if (isset($found[$manifest->key])) {
                throw ModuleDependencyException::duplicateKey($manifest->key);
            }

            $found[$manifest->key] = $manifest;
        }

        ksort($found);

        return $this->sort($found);
    }

    /**
     * Topological sort (depth-first) with cycle detection.
     *
     * @param  array<string, ModuleManifest>  $modules
     * @return array<string, ModuleManifest>
     */
    private function sort(array $modules): array
    {
        $sorted = [];
        $visiting = [];

        /** @param list<string> $chain */
        $visit = function (string $key, array $chain) use (&$visit, &$sorted, &$visiting, $modules): void {
            if (isset($sorted[$key])) {
                return;
            }

            if (isset($visiting[$key])) {
                throw ModuleDependencyException::circular([...$chain, $key]);
            }

            $visiting[$key] = true;

            foreach ($modules[$key]->dependsOn as $dependency) {
                if (! isset($modules[$dependency])) {
                    throw ModuleDependencyException::missing($key, $dependency);
                }

                $visit($dependency, [...$chain, $key]);
            }

            unset($visiting[$key]);
            $sorted[$key] = $modules[$key];
        };

        foreach (array_keys($modules) as $key) {
            $visit($key, []);
        }

        return $sorted;
    }
}
