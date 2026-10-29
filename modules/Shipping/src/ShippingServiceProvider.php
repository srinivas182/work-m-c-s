<?php

declare(strict_types=1);

namespace Wurk\Shipping;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Shipping\Contracts\Couriers;
use Wurk\Shipping\Support\Shipping;

final class ShippingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->mergeConfigFrom($this->modulePath('config/shipping.php'), 'shipping');
        $this->app->bind(Couriers::class, Shipping::class);
    }

    public function boot(): void
    {
        parent::boot();
        // Couriers' updates: one limit per business and courier (never shared across businesses on the same IP).
        RateLimiter::for('courier-notify', fn (Request $r) => Limit::perMinute(120)->by('courier:'.(string) $r->route('handle').':'.$r->path()));
    }

    public function menu(): array
    {
        return [['label' => 'Shipping', 'route' => 'tenant.shipping', 'icon' => 'truck', 'section' => 'Settings', 'permission' => 'shipping.manage', 'entitlement' => 'shop', 'order' => 845]];
    }

    public function permissions(): array
    {
        return [['key' => 'shipping.manage', 'label' => 'Connect couriers (Bob Go, Pargo)']];
    }
}
