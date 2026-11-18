<?php

declare(strict_types=1);

use Tests\Support\InteractsWithShards;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\OrderItem;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Receptionist\ReorderTool;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

/** Sprint 19.2c: the AI receptionist finds products and sends a link that fills the cart; payment happens on the website. */
beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'shop', 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop'],
    ], null);
    app(Catalog::class)->subscribe($this->shop->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    [$this->small, $this->large, $this->oil] = ($this->in)(function () {
        $tee = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'published', 'options' => [], 'description' => 'Soft cotton tee']);
        $small = Variant::query()->create(['product_id' => $tee->id, 'label' => 'Small', 'price_cents' => 25000, 'stock' => 3, 'position' => 0]);
        $large = Variant::query()->create(['product_id' => $tee->id, 'label' => 'Large', 'price_cents' => 27500, 'stock' => 0, 'position' => 1]);
        $oil = Product::query()->create(['name' => 'Hair oil', 'slug' => 'hair-oil', 'status' => 'draft', 'options' => []]);

        return [$small, $large, Variant::query()->create(['product_id' => $oil->id, 'price_cents' => 9900, 'stock' => null])];
    });
    $this->run = fn (string $name, array $args) => ($this->in)(fn () => collect(app(ReceptionistTools::class)->all())
        ->first(fn (ReceptionistTool $t) => $t->name() === $name)->run($args, ['conversation' => 'conv-1', 'channel' => 'web', 'customer' => []]));
});

it('finds published products with their options, prices and stock', function () {
    $r = ($this->run)('products', ['search' => 'cotton tee']);
    expect($r['products'])->toHaveCount(1)
        ->and($r['products'][0]['url'])->toEndWith('kasi-threads.'.config('wurk.domains.root').'/shop/kasi-t-shirt')
        ->and($r['products'][0]['options'])->toBe([
            ['variant_id' => $this->small->id, 'option' => 'Small', 'price' => 'R250.00', 'in_stock' => true],
            ['variant_id' => $this->large->id, 'option' => 'Large', 'price' => 'R275.00', 'in_stock' => false],
        ])
        ->and(($this->run)('products', ['search' => 'oil'])['products'])->toBe([]) // drafts aren't for sale
        ->and($r['can_order_online'])->toBeFalse();
});

it('makes a cart link only when the shop takes orders, for real products in stock', function () {
    $items = ['items' => [['variant_id' => $this->small->id, 'quantity' => 2]]];
    expect(fn () => ($this->run)('cart_link', $items))->toThrow(ReceptionistRefused::class, 'can’t take orders online');
    ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id()]);
        app(BusinessPayments::class)->setUpEft('FNB');
    });

    $r = ($this->run)('cart_link', $items);
    expect($r['cart_url'])->toEndWith("/cart?add={$this->small->id}:2")->and($r['subtotal'])->toBe('R500.00')
        ->and($r['items'])->toBe(['2 × Kasi T-shirt Small'])->and($r['card']['action']['label'])->toBe('Check out');
    expect(fn () => ($this->run)('cart_link', ['items' => [['variant_id' => $this->small->id, 'quantity' => 4]]]))->toThrow(ReceptionistRefused::class, 'Only 3');
    expect(fn () => ($this->run)('cart_link', ['items' => [['variant_id' => $this->oil->id, 'quantity' => 1]]]))->toThrow(ReceptionistRefused::class, 'isn’t in the shop');
    expect(fn () => ($this->run)('cart_link', ['items' => []]))->toThrow(ReceptionistRefused::class, 'which products');
});

it('offers "same as last time" on WhatsApp only, from the customer’s last paid order at today’s prices and stock', function () {
    ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id()]);
        app(BusinessPayments::class)->setUpEft('FNB');
        $order = Order::query()->create(['number' => 1, 'status' => 'done', 'payment_method' => 'online', 'fulfilment' => 'delivery', 'customer_name' => 'Zodwa',
            'customer_phone' => '082 555 0101', 'subtotal_cents' => 77500, 'total_cents' => 77500, 'paid_at' => now()->subWeek()]);
        foreach ([[$this->small, 'Small', 5], [$this->large, 'Large', 1]] as [$v, $label, $qty]) {
            OrderItem::query()->create(['order_id' => $order->id, 'variant_id' => $v->id, 'product_name' => 'Kasi T-shirt', 'variant_label' => $label,
                'unit_cents' => 25000, 'quantity' => $qty, 'stock_tracked' => true, 'line_cents' => 25000 * $qty]);
        }
    });
    $reorder = fn (array $customer) => ($this->in)(fn () => app(ReorderTool::class)->run([], ['conversation' => 'c', 'channel' => 'whatsapp', 'customer' => $customer]));

    $r = $reorder(['phone' => '+27825550101', 'verified' => true]);
    expect($r['items'])->toBe(['3 × Kasi T-shirt Small']) // only 3 left; the Large is sold out
        ->and($r['not_available'])->toBe(['Kasi T-shirt Large'])->and($r['last_time'])->toBe('delivered')->and($r['subtotal_today'])->toBe('R750.00')
        ->and($r['card']['action'])->toMatchArray(['label' => 'Check out'])->and($r['card']['action']['url'])->toEndWith("/cart?add={$this->small->id}:3");
    expect($reorder(['phone' => '+27820000000', 'verified' => true])['found'])->toBeFalse()
        ->and(fn () => $reorder(['phone' => '+27825550101']))->toThrow(ReceptionistRefused::class, 'Reordering works on WhatsApp'); // a typed number in a web chat proves nothing
});
