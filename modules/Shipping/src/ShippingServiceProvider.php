<?php

declare(strict_types=1);

namespace Wurk\Shipping;

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

    public function menu(): array
    {
        return [['label' => 'Shipping', 'route' => 'tenant.shipping', 'icon' => 'truck', 'section' => 'Settings', 'permission' => 'shipping.manage', 'entitlement' => 'shop', 'order' => 845]];
    }

    public function permissions(): array
    {
        return [['key' => 'shipping.manage', 'label' => 'Connect couriers (Bob Go, Pargo)']];
    }
}
