<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithShards;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\OrderItem;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderPlacer;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->shop, $fn);
    [$this->m, $this->l, $this->mug] = ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'delivery_enabled' => true, 'delivery_fee_cents' => 6000]);
        app(BusinessPayments::class)->setUpEft('FNB, account 62000000000');
        $tee = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'published', 'options' => ['Size']]);
        $mug = Product::query()->create(['name' => 'Enamel mug', 'slug' => 'enamel-mug', 'status' => 'published', 'options' => []]);

        return [
            Variant::query()->create(['product_id' => $tee->id, 'label' => 'M', 'values' => ['Size' => 'M'], 'price_cents' => 25000, 'stock' => 3]),
            Variant::query()->create(['product_id' => $tee->id, 'label' => 'L', 'values' => ['Size' => 'L'], 'price_cents' => 25000, 'stock' => 1]),
            Variant::query()->create(['product_id' => $mug->id, 'price_cents' => 9900, 'stock' => null]),
        ];
    });
    $this->who = ['name' => 'Sipho', 'phone' => '082 555 0101', 'email' => 'sipho@example.com'];
    $this->place = fn (array $lines, string $fulfilment = 'collection', array $who = []) => ($this->in)(fn () => app(OrderPlacer::class)->place($lines, [...$this->who, ...$who], $fulfilment, 'offline'));
    $this->stock = fn (Variant $v) => ($this->in)(fn () => $v->refresh()->stock);
});

it('explains when ordering online isn’t available', function () {
    ($this->in)(fn () => PaymentAccount::query()->where('provider', 'eft')->update(['active' => false]));
    expect(fn () => ($this->place)([['variant' => $this->m->id, 'quantity' => 1]]))->toThrow(CheckoutProblem::class, 'Ordering online isn’t available yet. Please contact us to order.');
});

it('takes stock off, prices from the server, numbers orders and copies names and prices in', function () {
    $first = ($this->place)([['variant' => $this->m->id, 'quantity' => 2], ['variant' => $this->mug->id, 'quantity' => 4]], 'delivery', ['address' => '12 Vilakazi St, Soweto']);
    $second = ($this->place)([['variant' => $this->m->id, 'quantity' => 1]]);
    expect([$first->number, $second->number])->toBe([1001, 1002])
        ->and($first->only(['subtotal_cents', 'delivery_cents', 'total_cents', 'status']))->toBe(['subtotal_cents' => 89600, 'delivery_cents' => 6000, 'total_cents' => 95600, 'status' => 'awaiting_payment'])
        ->and(($this->stock)($this->m))->toBe(0)->and(($this->stock)($this->mug))->toBeNull(); // untracked: not limited
    ($this->in)(function () use ($first) {
        Product::query()->where('slug', 'kasi-t-shirt')->update(['name' => 'Renamed later']);
        expect(OrderItem::query()->where('order_id', $first->id)->where('variant_label', 'M')->sole()->only(['product_name', 'unit_cents', 'quantity']))
            ->toBe(['product_name' => 'Kasi T-shirt', 'unit_cents' => 25000, 'quantity' => 2]);
    });
});

it('never oversells — and changes nothing when one line can’t be met', function () {
    ($this->place)([['variant' => $this->l->id, 'quantity' => 1]]);
    expect(fn () => ($this->place)([['variant' => $this->l->id, 'quantity' => 1]]))->toThrow(CheckoutProblem::class, 'Kasi T-shirt (L) has just sold out.');
    expect(fn () => ($this->place)([['variant' => $this->mug->id, 'quantity' => 2], ['variant' => $this->m->id, 'quantity' => 5]]))
        ->toThrow(CheckoutProblem::class, 'Only 3 of Kasi T-shirt (M) left — please change the quantity.');
    expect(($this->stock)($this->m))->toBe(3)->and(($this->in)(fn () => Order::query()->count()))->toBe(1); // nothing saved for the failed order
});

it('refuses drafts, and needs an address for delivery', function () {
    ($this->in)(fn () => Product::query()->where('slug', 'enamel-mug')->update(['status' => 'draft']));
    expect(fn () => ($this->place)([['variant' => $this->mug->id, 'quantity' => 1]]))->toThrow(CheckoutProblem::class, 'Enamel mug isn’t available any more. Please remove it from your cart.')
        ->and(fn () => ($this->place)([['variant' => $this->m->id, 'quantity' => 1]], 'delivery'))->toThrow(CheckoutProblem::class, 'Please add the delivery address.');
});

it('returns the stock when an order is cancelled or expires', function () {
    $order = ($this->place)([['variant' => $this->m->id, 'quantity' => 2]]);
    expect(DB::table('shop_holds')->count())->toBe(1)->and(($this->stock)($this->m))->toBe(1);
    ($this->in)(fn () => app(OrderPlacer::class)->release($order, 'cancelled', null));
    expect(($this->stock)($this->m))->toBe(3)->and(DB::table('shop_holds')->count())->toBe(0)
        ->and(($this->in)(fn () => $order->refresh()->status))->toBe('cancelled');
});

it('cancels unpaid orders after the hold time and returns their stock', function () {
    ($this->place)([['variant' => $this->m->id, 'quantity' => 2]]);
    $this->travel(71)->hours();
    $this->artisan('shop:expire-orders')->expectsOutput('0 order(s) expired.');
    $this->travel(2)->hours();
    $this->artisan('shop:expire-orders')->expectsOutput('1 order(s) expired.');
    expect(($this->stock)($this->m))->toBe(3)->and(($this->in)(fn () => Order::query()->sole()->status))->toBe('expired');
});
