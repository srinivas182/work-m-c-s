<?php

declare(strict_types=1);

namespace Wurk\Plans\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Models\PackageVersion;
use Wurk\Plans\Support\ModuleCatalogue;

/**
 * Packages are edited in two parts: presentation (name, description, order,
 * visibility) changes in place; anything that defines what is sold creates a
 * new immutable version. Existing subscribers stay on their version
 * (grandfathering) until they change package.
 */
final readonly class PackageCatalog
{
    public function __construct(private ModuleCatalogue $catalogue, private AuditLog $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ?string $by): Package
    {
        $this->assertModules($data['modules'] ?? []);

        return DB::transaction(function () use ($data, $by): Package {
            $package = Package::query()->create(self::presentation($data) + ['key' => $data['key'], 'type' => $data['type']]);
            $version = $this->newVersion($package, $data, $by);
            $this->audit->record('package.created', 'package', $package->id, ['key' => $package->key, 'version' => 1, 'terms' => self::terms($version)]);

            return $package->fresh() ?? $package;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(Package $package, array $data, ?string $by): Package
    {
        $this->assertModules($data['modules'] ?? []);

        return DB::transaction(function () use ($package, $data, $by): Package {
            $package->update(self::presentation($data));
            $current = $package->currentVersion;
            $incoming = self::commercial($data);

            if ($current === null || self::terms($current) !== $incoming) {
                $version = $this->newVersion($package, $data, $by);
                $this->audit->record('package.version_created', 'package', $package->id, [
                    'version' => $version->version,
                    'before' => $current === null ? null : self::terms($current),
                    'after' => self::terms($version),
                ]);
            }

            return $package->fresh() ?? $package;
        });
    }

    public function archive(Package $package, bool $archived): void
    {
        $package->update(['status' => $archived ? 'archived' : 'active']);
        $this->audit->record($archived ? 'package.archived' : 'package.restored', 'package', $package->id, ['key' => $package->key]);
    }

    /** @param  array<string, mixed>  $data */
    private function newVersion(Package $package, array $data, ?string $by): PackageVersion
    {
        $next = (int) PackageVersion::query()->where('package_id', $package->id)->max('version') + 1;
        $version = PackageVersion::query()->create(self::commercial($data) + [
            'package_id' => $package->id, 'version' => $next, 'currency' => 'ZAR', 'created_by' => $by,
        ]);
        $package->forceFill(['current_version_id' => $version->id])->save();

        return $version;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function presentation(array $data): array
    {
        return array_intersect_key($data, array_flip(['name', 'description', 'visibility', 'display_order']));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function commercial(array $data): array
    {
        $terms = [];
        $modules = array_values(array_unique(array_map('strval', (array) ($data['modules'] ?? []))));
        sort($modules);
        foreach (PackageVersion::COMMERCIAL as $field) {
            $value = $data[$field] ?? null;
            $terms[$field] = match ($field) {
                'multi_branch', 'custom_domain' => (bool) $value,
                'modules' => $modules,
                'managed_fee_cents' => $value === null || $value === '' ? null : (int) $value,
                default => (int) $value,
            };
        }

        return $terms;
    }

    /** @return array<string, mixed> */
    private static function terms(PackageVersion $version): array
    {
        return self::commercial($version->only(PackageVersion::COMMERCIAL));
    }

    private function assertModules(mixed $modules): void
    {
        $unknown = array_diff((array) $modules, $this->catalogue->keys());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['modules' => 'Unknown modules: '.implode(', ', $unknown).'.']);
        }
    }
}
