<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithShards;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderManager;
use Wurk\Shop\Support\OrderPayments;
use Wurk\Shop\Support\OrderPlacer;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class, FakesYoco::class);

beforeEach(function () {
    $this->fakeYoco();
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $this->connectYoco($this->shop);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->shop, $fn);
    $this->tee = ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'delivery_enabled' => true, 'delivery_fee_cents' => 5000]);
        app(BusinessPayments::class)->setUpEft('FNB, account 62000000000');
        $p = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'published', 'options' => []]);

        return Variant::query()->create(['product_id' => $p->id, 'price_cents' => 25000, 'stock' => 5]);
    });
    $this->order = fn (string $method, string $fulfilment = 'collection') => ($this->in)(function () use ($method, $fulfilment) {
        $o = app(OrderPlacer::class)->place([['variant' => $this->tee->id, 'quantity' => 2]], ['name' => 'Sipho', 'phone' => '082', 'address' => '12 Vilakazi St'], $fulfilment, $method);
        if ($method === 'card') {
            app(BusinessPayments::class)->start('shop-'.$o->id, $o->total_cents, 'Order', ['name' => 'Sipho'], ['return' => 'https://x', 'cancel' => 'https://x']);
            app(OrderPayments::class)->settle($o); // Yoco says paid
        }

        return $o->refresh();
    });
    $this->manage = fn () => app(OrderManager::class);
    $this->stock = fn () => ($this->in)(fn () => $this->tee->refresh()->stock);
    $this->events = fn (Order $o) => ($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('shop_order_events')->where('order_id', $o->id)->orderBy('id')->pluck('event')->all());
});

it('walks an EFT order through its journey — and refuses steps that don’t follow', function () {
    $o = ($this->order)('offline');
    ($this->in)(function () use ($o) {
        expect(fn () => ($this->manage)()->advance($o, 'done', 'Thabo'))->toThrow(CheckoutProblem::class, 'That step doesn’t follow from where this order is.');
        ($this->manage)()->advance($o, 'paid', 'Thabo');
        expect($o->refresh()->paid_at)->not->toBeNull();
        ($this->manage)()->advance($o, 'packed', 'Thabo');
        expect(fn () => ($this->manage)()->advance($o, 'out_for_delivery', 'Thabo'))->toThrow(CheckoutProblem::class, 'This order is being collected, not delivered.');
        ($this->manage)()->advance($o, 'ready', 'Thabo');
        ($this->manage)()->advance($o, 'done', 'Thabo');
    });
    expect(($this->events)($o))->toBe(['placed', 'paid', 'packed', 'ready', 'done'])
        ->and(DB::table('shop_holds')->count())->toBe(0);
});

it('never lets a card order be marked paid by hand', function () {
    $this->yoco['paid'] = false;
    $o = ($this->order)('card');
    ($this->in)(fn () => expect(fn () => ($this->manage)()->advance($o, 'paid', 'Thabo'))->toThrow(CheckoutProblem::class, 'Card payments are marked paid automatically once the payment is confirmed.'));
});

it('cancels a paid card order: stock back and a full refund — but not once it has left', function () {
    $o = ($this->order)('card');
    expect($o->status)->toBe('paid')->and(($this->stock)())->toBe(3);
    ($this->in)(fn () => expect(($this->manage)()->cancel($o, 'Thabo', 'Out of stock'))->toBeTrue());
    expect(($this->stock)())->toBe(5)->and($this->yocoRefunds())->toBe(1)
        ->and(($this->in)(fn () => $o->refresh()->only(['status', 'refunded_cents'])))->toBe(['status' => 'cancelled', 'refunded_cents' => 50000])
        ->and(($this->events)($o))->toBe(['placed', 'paid', 'cancelled', 'refunded']);

    $shipped = ($this->order)('card', 'delivery');
    ($this->in)(function () use ($shipped) {
        ($this->manage)()->advance($shipped, 'out_for_delivery', 'Thabo');
        expect(fn () => ($this->manage)()->cancel($shipped, 'Thabo'))->toThrow(CheckoutProblem::class, 'This order has already left — refund it instead.');
    });
});

it('refunds part or all of a paid order, never more than is left', function () {
    $o = ($this->order)('card', 'delivery'); // R500 + R50 delivery
    ($this->in)(function () use ($o) {
        ($this->manage)()->refund($o, 5000, false, 'Thabo', 'Late delivery');
        expect(fn () => ($this->manage)()->refund($o, 60000, false, 'Thabo'))->toThrow(CheckoutProblem::class, 'Refund up to R500.00 (what’s left of this order).');
        ($this->manage)()->refund($o, null, true, 'Thabo'); // the rest, stock returned
        expect($o->refresh()->refunded_cents)->toBe(55000);
    });
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refund') && $r['amount'] === 5000);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refund') && $r['amount'] === 50000);
    expect(($this->stock)())->toBe(5);
});
