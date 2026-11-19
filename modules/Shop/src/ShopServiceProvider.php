<?php

declare(strict_types=1);

namespace Wurk\Shop;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Access\Contracts\BranchRestriction;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\ContactsBackfillRequested;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\AssistantTools;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Contracts\BusinessSetup;
use Wurk\Platform\Contracts\DataExports;
use Wurk\Platform\Contracts\FinishedVisits;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Platform\Contracts\NeedsYouNow;
use Wurk\Platform\Contracts\OwnerDigest;
use Wurk\Platform\Contracts\RecentlyDeleted;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Platform\Contracts\TodayList;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Money\Money;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Shipping\Contracts\ShipmentUpdated;
use Wurk\Shop\Assistant\AddStockTool;
use Wurk\Shop\Assistant\MarkOrderPaidTool;
use Wurk\Shop\Console\DemoShopCommand;
use Wurk\Shop\Console\ExpireOrdersCommand;
use Wurk\Shop\Digest\ShopDigest;
use Wurk\Shop\Listeners\MarkDeliveredOrders;
use Wurk\Shop\Mail\OrderUpdateMail;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopCategory;
use Wurk\Shop\Receptionist\CartLinkTool;
use Wurk\Shop\Receptionist\ProductsTool;
use Wurk\Shop\Receptionist\ReorderTool;
use Wurk\Shop\Reports\SalesSection;
use Wurk\Shop\Reports\StockSection;
use Wurk\Shop\Setup\ShopSetupStep;
use Wurk\Shop\Support\DeletedCatalog;
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
        $this->callAfterResolving(OwnerDigest::class, fn (OwnerDigest $d) => $d->register(ShopDigest::class)); // the owner's evening WhatsApp summary (21.6)
        $this->callAfterResolving(BusinessSetup::class, fn (BusinessSetup $s) => $s->register(ShopSetupStep::class)); // set up by talking (23.1)
        // Recently deleted (16.6b): products and categories.
        // The AI receptionist (19.2c): find products, and a link that fills the cart on the website.
        $this->callAfterResolving(ReceptionistTools::class, function (ReceptionistTools $t): void {
            $t->register(ProductsTool::class);
            $t->register(CartLinkTool::class);
            $t->register(ReorderTool::class); // 21.5: "same as last time?" on WhatsApp
        });
        $this->callAfterResolving(RecentlyDeleted::class, function (RecentlyDeleted $d): void {
            $d->register('products', 'Products', 'shop.manage',
                fn (\DateTimeInterface $since) => Product::onlyTrashed()->where('deleted_at', '>', $since)->orderByDesc('deleted_at')->get()
                    ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'deleted_at' => $p->deleted_at ?? now()])->all(),
                fn (string $id) => $this->app->make(DeletedCatalog::class)->restoreProduct($id),
                fn (\DateTimeInterface $before) => $this->app->make(DeletedCatalog::class)->purgeProducts($before));
            $d->register('shop-categories', 'Shop categories', 'shop.manage',
                fn (\DateTimeInterface $since) => ShopCategory::onlyTrashed()->where('deleted_at', '>', $since)->orderByDesc('deleted_at')->get()
                    ->map(fn (ShopCategory $c) => ['id' => $c->id, 'name' => $c->name, 'deleted_at' => $c->deleted_at ?? now()])->all(),
                fn (string $id) => $this->app->make(DeletedCatalog::class)->restoreCategory($id),
                fn (\DateTimeInterface $before) => $this->app->make(DeletedCatalog::class)->purgeCategories($before));
        });
        // Your data (16.6): what this module keeps, for the full export.
        $this->callAfterResolving(DataExports::class, function (DataExports $x): void {
            $rows = fn (string $table, string $order) => fn () => DB::connection(app(TenantContext::class)->connectionName())->table($table)
                ->where('tenant_id', app(TenantContext::class)->id())->orderBy($order)->lazy(500)->map(fn ($r) => (array) $r);
            $x->table('products', 'Products', 'shop.manage', $rows('shop_products', 'created_at'));
            $x->table('product-variants', 'Product variants and stock', 'shop.manage', $rows('shop_variants', 'created_at'));
            $x->table('orders', 'Orders', 'shop.orders', $rows('shop_orders', 'created_at'));
            $x->table('order-items', 'Order items', 'shop.orders', $rows('shop_order_items', 'id'));
        });
        // Reports → Stock (Sprint 15.1b).
        $this->callAfterResolving(BusinessReports::class, function (BusinessReports $r): void {
            $r->register('shop.stock', 150, fn (ReportPeriod $p) => ($p->forOwners || Gate::allows('reports.view'))
                && app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'shop')
                ? app(StockSection::class)->build($p) : null);
        });
        // Reports → Shop sales (Sprint 15.1a): for people who see reports, at businesses whose package has the shop.
        $this->callAfterResolving(BusinessReports::class, function (BusinessReports $r): void {
            $r->register('shop.sales', 100, fn (ReportPeriod $p) => ($p->forOwners || Gate::allows('reports.view'))
                && app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'shop')
                ? app(SalesSection::class)->build($p) : null);
        });
        $this->loadViewsFrom($this->modulePath('resources/views'), 'shop');
        Event::listen(BusinessPaymentReceived::class, [OrderPayments::class, 'handle']);
        // Who owes me (23.5): pay-later orders not yet paid.
        // Reviews (25.3): orders delivered or collected (done).
        $this->callAfterResolving(FinishedVisits::class, fn (FinishedVisits $v) => $v->register('shop', fn (\DateTimeInterface $from, \DateTimeInterface $until) => Order::query()
            ->where('status', 'done')->where('updated_at', '>=', $from)->where('updated_at', '<', $until)->get()
            ->map(fn (Order $o) => ['key' => 'order:'.$o->id, 'kind' => 'order', 'title' => 'order #'.$o->number, 'customer' => $o->customer_name,
                'phone' => $o->customer_phone, 'email' => $o->customer_email, 'finished_at' => $o->updated_at?->toIso8601String() ?? now()->toIso8601String()])));
        // Home's "Needs you now" (26.3): orders to pack, and orders ready but not collected yet.
        $this->callAfterResolving(NeedsYouNow::class, fn (NeedsYouNow $n) => $n->register('orders', function (): array {
            if (! Gate::allows('shop.orders') || ! app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'shop')) {
                return [];
            }
            $handle = app(TenantContext::class)->current()->handle();

            return array_values(Order::query()->whereIn('status', ['paid', 'ready'])->orderByDesc('paid_at')->limit(8)->get()->map(fn (Order $o) => ['id' => 'order:'.$o->id,
                'kind' => $o->status === 'paid' ? __('Order to pack') : __('Waiting to be collected'), 'title' => '#'.$o->number.' · '.$o->customer_name,
                'detail' => Money::format($o->total_cents), 'at' => ($o->paid_at ?? $o->created_at)->toIso8601String(), 'url' => "/{$handle}/shop/orders/{$o->id}",
                'actions' => [$o->status === 'paid' ? ['label' => __('Packed'), 'url' => "/{$handle}/shop/orders/{$o->id}/advance", 'data' => ['to' => 'packed'], 'tone' => 'primary']
                    : ['label' => __('Collected'), 'url' => "/{$handle}/shop/orders/{$o->id}/advance", 'data' => ['to' => 'done'], 'tone' => 'primary']]])->all());
        }));
        // Today (26.2): paid orders still to pack, for the people who handle orders (only their branches').
        $this->callAfterResolving(TodayList::class, fn (TodayList $t) => $t->register('orders', 300, function (string $identityId): ?array {
            $user = Auth::getProvider()->retrieveById($identityId);
            if ($user === null || ! app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'shop') || ! Gate::forUser($user)->allows('shop.orders')) {
                return null;
            }
            $branches = app(BranchRestriction::class)->branchRestriction($identityId);
            $orders = Order::query()->where('status', 'paid')->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches))->orderBy('paid_at')->limit(15)->get();
            $handle = app(TenantContext::class)->current()->handle();

            return ['title' => __('Orders to pack'), 'empty' => __('Nothing to pack.'), 'link' => ['label' => __('All orders'), 'url' => route('tenant.shop.orders')],
                'items' => array_values($orders->map(fn (Order $o) => ['id' => $o->id, 'title' => '#'.$o->number.' · '.$o->customer_name,
                    'detail' => ($o->fulfilment === 'delivery' ? __('Delivery') : __('Collection')).' · '.Money::format($o->total_cents), 'time' => null, 'badge' => null,
                    'url' => route('tenant.shop.orders.show', ['order' => $o->id]),
                    'actions' => [['label' => __('Packed'), 'url' => "/{$handle}/shop/orders/{$o->id}/advance", 'data' => ['to' => 'packed'], 'tone' => 'primary']]])->all())];
        }));
        // Ask WURK (26.1, in the app and on WhatsApp): mark a pay-later order paid, add stock that arrived.
        $this->callAfterResolving(AssistantTools::class, function (AssistantTools $t): void {
            $t->register(MarkOrderPaidTool::class);
            $t->register(AddStockTool::class);
        });
        $this->callAfterResolving(MoneyOwed::class, fn (MoneyOwed $o) => $o->register('shop', fn () => Order::query()->where('status', 'awaiting_payment')->where('payment_method', 'offline')
            ->orderBy('created_at')->limit(200)->get()->map(fn (Order $order) => ['key' => 'order:'.$order->id, 'kind' => 'order', 'title' => 'Order #'.$order->number.' (pay later)',
                'customer' => $order->customer_name, 'phone' => $order->customer_phone, 'email' => $order->customer_email, 'amount_cents' => $order->dueCents(),
                'since' => $order->created_at->toDateString(), 'due' => null, 'link' => '/shop/orders/'.$order->id, 'settle' => '/shop/orders/'.$order->id.'/advance'])->values()->all()));
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
