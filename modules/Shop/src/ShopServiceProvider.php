<?php

declare(strict_types=1);

namespace Wurk\Shop;

use Wurk\Platform\Modules\ModuleServiceProvider;

final class ShopServiceProvider extends ModuleServiceProvider
{
    public function permissions(): array
    {
        return [
            ['key' => 'shop.manage', 'label' => 'Manage products, stock and orders'],
        ];
    }

    public function menu(): array
    {
        return [
            ['label' => 'Products', 'route' => 'tenant.shop.products', 'icon' => 'package', 'section' => 'Shop', 'permission' => 'shop.manage', 'entitlement' => 'shop', 'order' => 300],
        ];
    }
}
