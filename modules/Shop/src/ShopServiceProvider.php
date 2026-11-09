<?php

declare(strict_types=1);

namespace Wurk\Shop;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\ContactsBackfillRequested;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Shipping\Contracts\ShipmentUpdated;
use Wurk\Shop\Console\DemoShopCommand;
use Wurk\Shop\Console\ExpireOrdersCommand;
use Wurk\Shop\Listeners\MarkDeliveredOrders;
use Wurk\Shop\Mail\OrderUpdateMail;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Reports\SalesSection;
use Wurk\Shop\Reports\StockSection;
use Wurk\Shop\Support\OrderMessages;
use Wurk\Shop\Support\OrderPayments;
use Wurk\Shop\Support\OrderStatusChanged;
use Wurk\Shop\Support\ShopCatalog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\ProductCatalog;

final class ShopServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->callAfterResolving(MessageTemplates::class, function (MessageTemplates $t): void {
            foreach (OrderUpdateMail::definitions() as $key => $def) {
                $t->define($key, $def); // Settings → Messages
            }
        });
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
        // Reports → Stock (Sprint 15.1b).
        $this->callAfterResolving(BusinessReports::class, function (BusinessReports $r): void {
            $r->register('shop.stock', 150, fn (ReportPeriod $p) => Gate::allows('reports.view')
                && app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'shop')
                ? app(StockSection::class)->build($p) : null);
        });
        // Reports → Shop sales (Sprint 15.1a): for people who see reports, at businesses whose package has the shop.
        $this->callAfterResolving(BusinessReports::class, function (BusinessReports $r): void {
            $r->register('shop.sales', 100, fn (ReportPeriod $p) => Gate::allows('reports.view')
                && app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'shop')
                ? app(SalesSection::class)->build($p) : null);
        });
        $this->loadViewsFrom($this->modulePath('resources/views'), 'shop');
        Event::listen(BusinessPaymentReceived::class, [OrderPayments::class, 'handle']);
        Event::listen(OrderStatusChanged::class, [OrderMessages::class, 'handle']);
        Event::listen(ShipmentUpdated::class, [MarkDeliveredOrders::class, 'handle']); // a courier delivered it
        Event::listen(ContactsBackfillRequested::class, function (): void { // existing customers → contacts
            $book = app(ContactBook::class);
            Order::query()->orderBy('id')->chunk(500, function ($rows) use ($book): void {
                foreach ($rows as $o) {
                    $book->remember($o->customer_name, $o->customer_phone, $o->customer_email, 'order', $o->id, $o->branch_id, "Order #{$o->number}");
                }
            });
        });
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
