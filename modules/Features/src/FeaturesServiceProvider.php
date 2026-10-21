<?php

declare(strict_types=1);

namespace Wurk\Features;

use Laravel\Pennant\Feature;
use Wurk\Features\Support\FeatureFlags;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Contracts\TenantContext;

final class FeaturesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureFlags::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'features.manage', 'label' => 'Roll out new features', 'scope' => 'platform'],
            ['key' => 'features.opt-in', 'label' => 'Switch on new features early'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Feature rollouts', 'route' => 'admin.features', 'icon' => 'flag', 'section' => 'Platform', 'permission' => 'features.manage', 'order' => 40],
            ['label' => 'New features', 'route' => 'tenant.features', 'icon' => 'flag', 'section' => 'Settings', 'permission' => 'features.opt-in', 'order' => 790],
        ];
    }

    protected function bootModule(): void
    {
        // Feature::active('x') inside a business is evaluated for that business.
        Feature::resolveScopeUsing(function () {
            $context = $this->app->make(TenantContext::class);

            return $context->has() ? 'tenant:'.$context->id() : null;
        });

        $this->app->booted(function (): void {
            $flags = $this->app->make(FeatureFlags::class);
            foreach (array_keys($flags->declared()) as $key) {
                Feature::define($key, fn (?string $scope) => $flags->evaluate($key, $scope));
            }
        });
    }
}
