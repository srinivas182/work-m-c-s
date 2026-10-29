<?php

declare(strict_types=1);

namespace Wurk\Shop;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Shop\Console\DemoShopCommand;
use Wurk\Shop\Console\ExpireOrdersCommand;
use Wurk\Shop\Support\OrderMessages;
use Wurk\Shop\Support\OrderPayments;
use Wurk\Shop\Support\OrderStatusChanged;
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
            ['key' => 'shop.manage', 'label' => 'Manage products, prices, stock and shop settings'],
            ['key' => 'shop.orders', 'label' => 'See and handle orders'],
        ];
    }

    public function menu(): array
    {
        return [
            ['label' => 'Orders', 'route' => 'tenant.shop.orders', 'icon' => 'shopping-bag', 'section' => 'Shop', 'permission' => 'shop.orders', 'entitlement' => 'shop', 'order' => 295],
            ['label' => 'Products', 'route' => 'tenant.shop.products', 'icon' => 'package', 'section' => 'Shop', 'permission' => 'shop.manage', 'entitlement' => 'shop', 'order' => 300],
            ['label' => 'Shop settings', 'route' => 'tenant.shop.settings', 'icon' => 'settings', 'section' => 'Shop', 'permission' => 'shop.manage', 'entitlement' => 'shop', 'order' => 310],
        ];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'shop');
        Event::listen(BusinessPaymentReceived::class, [OrderPayments::class, 'handle']);
        Event::listen(OrderStatusChanged::class, [OrderMessages::class, 'handle']);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('shop:expire-orders')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();
        });
        // Public limits per business AND visitor (never across all of WURK — shared mobile IPs).
        $visitor = fn (Request $r, string $name) => $name.'|'.(string) $r->route('handle').'|'.$r->ip();
        RateLimiter::for('shop-read', fn (Request $r) => Limit::perMinute(60)->by($visitor($r, 'shop-read')));
        RateLimiter::for('shop-create', fn (Request $r) => Limit::perMinutes(10, 10)->by($visitor($r, 'shop-create')));
        if ($this->app->runningInConsole()) {
            $this->commands([DemoShopCommand::class, ExpireOrdersCommand::class]);
        }
    }
}
