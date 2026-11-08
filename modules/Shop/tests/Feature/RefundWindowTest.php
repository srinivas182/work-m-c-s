<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Support\ReturnWindow;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderManager;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->shop, $fn);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop'],
    ], null);
    app(Catalog::class)->subscribe($this->shop->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->tee = ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'collection_address' => '12 Vilakazi St, Soweto']);
        app(BusinessPayments::class)->setUpEft("FNB Kasi Threads\nAccount 6200 0000 000, branch 250655");
        $tee = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'published', 'options' => []]);

        return Variant::query()->create(['product_id' => $tee->id, 'price_cents' => 25000, 'stock' => 5]);
    });
    $this->paid = function () {
        $this->postJson('http://kasi-threads.'.config('wurk.domains.root').'/cart/checkout', ['lines' => [['variant' => $this->tee->id, 'quantity' => 2]], 'name' => 'Sipho',
            'phone' => '082 555 0101', 'fulfilment' => 'collection', 'consent' => '1'])->assertCreated();

        return ($this->in)(function () {
            $order = Order::query()->latest('number')->first();
            app(OrderManager::class)->advance($order, 'paid', 'Thabo');

            return $order->refresh();
        });
    };
    $this->problem = function (callable $fn): ?string {
        try {
            ($this->in)($fn);
        } catch (CheckoutProblem $e) {
            return $e->getMessage();
        }

        return null;
    };
});

it('refunds within the return window, and refuses refunds and cancelling a paid order once it has closed', function () {
    $order = ($this->paid)();
    expect(($this->problem)(fn () => app(OrderManager::class)->refund($order, 10000, false, 'Thabo')))->toBeNull(); // within 14 days
    $this->travel(15)->days();
    $closed = ($this->problem)(fn () => app(OrderManager::class)->refund($order->refresh(), 10000, false, 'Thabo'));
    expect($closed)->toStartWith('Refunds for this order closed on ')->toEndWith('(14 days after payment).')
        ->and(($this->problem)(fn () => app(OrderManager::class)->cancel($order->refresh(), 'Thabo')))->toBe($closed)
        ->and(($this->in)(fn () => $order->refresh()->refunded_cents))->toBe(10000); // only the refund made in time
});

it('gives no refunds when the business gives none (0 days)', function () {
    ($this->in)(fn () => app(ReturnWindow::class)->set(0));
    $order = ($this->paid)();
    expect(($this->problem)(fn () => app(OrderManager::class)->refund($order, 10000, false, 'Thabo')))->toBe('This business doesn’t give refunds.');
});
