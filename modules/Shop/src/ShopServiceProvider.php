<?php

declare(strict_types=1);

namespace Wurk\Shop;

use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Shop\Console\DemoShopCommand;
use Wurk\Shop\Support\ShopCatalog;
use Wurk\Website\Contracts\ProductCatalog;

final class ShopServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(ProductCatalog::class, ShopCatalog::class); // the website's shop sections read this
    }

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

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoShopCommand::class]);
        }
    }
}
