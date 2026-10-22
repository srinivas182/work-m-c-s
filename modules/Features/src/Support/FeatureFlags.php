<?php

declare(strict_types=1);

namespace Wurk\Features\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Modules\ModuleServiceProvider;

/**
 * Rollout rules for feature flags declared by modules. Pennant caches each
 * business's value, so every rule change purges that flag and every opt-in
 * change forgets that business's value; opt-ins live in their own table.
 */
final class FeatureFlags
{
    public const OFF = 'off';

    public const OPT_IN = 'opt_in';

    public const PERCENTAGE = 'percentage';

    public const ON = 'on';

    /** Days an opt-in feature stays optional before it becomes the default (PRD §14). */
    public const OPT_IN_WINDOW_DAYS = 90;

    /** @var array<string, array{label: string, description: string, module: string}>|null */
    private ?array $declared = null;

    public function __construct(private readonly Application $app, private readonly AuditLog $audit) {}

    /** @return array<string, array{label: string, description: string, module: string}> */
    public function declared(): array
    {
        if ($this->declared !== null) {
            return $this->declared;
        }

        $found = [];
        foreach ($this->app->getProviders(ModuleServiceProvider::class) as $provider) {
            if ($provider instanceof ModuleServiceProvider) {
                foreach ($provider->features() as $key => $feature) {
                    $found[$key] = ['label' => $feature['label'], 'description' => $feature['description'] ?? '', 'module' => class_basename($provider)];
                }
            }
        }
        ksort($found);

        return $this->declared = $found;
    }

    public function flush(): void
    {
        $this->declared = null;
    }

    /** @return array{mode: string, percentage: int, default_on_at: string|null} */
    public function rule(string $key): array
    {
        $row = DB::table('feature_rollouts')->where('key', $key)->first();

        return [
            'mode' => (string) ($row->mode ?? self::OFF),
            'percentage' => (int) ($row->percentage ?? 0),
            'default_on_at' => $row?->default_on_at === null ? null : (string) $row->default_on_at,
        ];
    }

    /** Pennant resolver. Scope is "tenant:{id}" inside a business, otherwise null. */
    public function evaluate(string $key, ?string $scope): bool
    {
        $rule = $this->rule($key);
        $tenantId = $scope !== null && str_starts_with($scope, 'tenant:') ? substr($scope, 7) : null;

        return match ($rule['mode']) {
            self::ON => true,
            self::PERCENTAGE => $tenantId !== null && (crc32($key.'|'.$tenantId) % 100) < $rule['percentage'],
            self::OPT_IN => $tenantId !== null && (
                ($rule['default_on_at'] !== null && now()->gte($rule['default_on_at']))
                || DB::table('feature_opt_ins')->where('tenant_id', $tenantId)->where('key', $key)->exists()
            ),
            default => false,
        };
    }

    public function setRule(string $key, string $mode, int $percentage, ?string $by): void
    {
        $previous = $this->rule($key);
        $defaultOn = $mode === self::OPT_IN
            ? ($previous['mode'] === self::OPT_IN && $previous['default_on_at'] !== null ? $previous['default_on_at'] : now()->addDays(self::OPT_IN_WINDOW_DAYS))
            : null;

        DB::table('feature_rollouts')->updateOrInsert(['key' => $key], [
            'mode' => $mode,
            'percentage' => $mode === self::PERCENTAGE ? max(0, min(100, $percentage)) : 0,
            'default_on_at' => $defaultOn,
            'updated_by' => $by,
            'updated_at' => now(),
            'created_at' => now(),
        ]);

        Feature::purge($key);
        $this->audit->record('feature.rule_changed', 'feature', $key, ['before' => $previous, 'after' => $this->rule($key)], ['platform' => true]);
    }

    public function setOptIn(string $tenantId, string $key, bool $on, ?string $by): void
    {
        if ($on) {
            DB::table('feature_opt_ins')->updateOrInsert(['tenant_id' => $tenantId, 'key' => $key], ['by' => $by, 'updated_at' => now(), 'created_at' => now()]);
        } else {
            DB::table('feature_opt_ins')->where('tenant_id', $tenantId)->where('key', $key)->delete();
        }

        Feature::for('tenant:'.$tenantId)->forget($key);
        $this->audit->record($on ? 'feature.opted_in' : 'feature.opted_out', 'feature', $key, [], ['platform' => false]);
    }

    public function optInCount(string $key): int
    {
        return DB::table('feature_opt_ins')->where('key', $key)->count();
    }
}
