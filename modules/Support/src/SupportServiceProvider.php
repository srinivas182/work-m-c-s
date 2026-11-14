<?php

declare(strict_types=1);

namespace Wurk\Support;

use Illuminate\Routing\Router;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Support\Http\Middleware\ShareSupport;

final class SupportServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [
            ['label' => 'Contact support', 'route' => 'tenant.support', 'icon' => 'life-buoy', 'section' => 'Settings', 'order' => 990],
            ['label' => 'Support inbox', 'route' => 'admin.support', 'icon' => 'life-buoy', 'section' => 'Platform', 'permission' => 'support.manage', 'order' => 55],
        ];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'support.tickets', 'label' => 'See and reply to every support ticket from this business'],
            ['key' => 'support.manage', 'label' => 'Answer support tickets from businesses', 'scope' => 'platform'],
        ];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'support');
        $this->app->make(Router::class)->pushMiddlewareToGroup('tenant.back-office', ShareSupport::class);
    }
}
