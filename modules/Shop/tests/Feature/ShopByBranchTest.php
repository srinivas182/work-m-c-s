<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Mail\NewOrderMail;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\BranchStock;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderManager;
use Wurk\Shop\Support\OrderMessages;
use Wurk\Shop\Support\OrderPlacer;
use Wurk\Team\Support\Settlements;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $p = app(PackageCatalog::class)->create([
        'key' => 'pro', 'name' => 'Pro', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 99900, 'yearly_price_cents' => 999000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 10,
        'multi_branch' => true, 'max_branches' => 5, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop'],
    ], null);
    app(Catalog::class)->subscribe($this->shop->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    [$this->main, $this->sandton, $this->tee] = ($this->in)(function () {
        app(BranchDirectory::class)->add('Sandton', '12 Rivonia Rd', null);
        $main = app(BranchDirectory::class)->main();
        $sandton = collect(app(BranchDirectory::class)->open())->firstWhere('name', 'Sandton')['id'];
        app(BusinessPayments::class)->setUpEft('FNB 62000000000');
        $product = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'published', 'options' => []]);
        $tee = Variant::query()->create(['product_id' => $product->id, 'price_cents' => 25000, 'stock' => 0]);
        app(BranchStock::class)->set($tee, [$main => 1, $sandton => 3]);
        app(BranchStock::class)->setPrices($tee, [$sandton => 27000]);

        return [$main, $sandton, $tee->refresh()];
    });
    $this->order = fn (string $branch, int $qty) => ($this->in)(fn () => app(OrderPlacer::class)->place([['variant' => $this->tee->id, 'quantity' => $qty]], ['name' => 'Sipho', 'phone' => '082'], 'collection', 'offline', $branch));
    $this->at = fn (string $branch) => ($this->in)(fn () => app(BranchStock::class)->at($this->tee->refresh(), $branch));
});

it('keeps stock per branch, sells from the chosen branch at its price, and returns stock there', function () {
    expect($this->tee->stock)->toBe(4);
    $order = ($this->order)($this->sandton, 2);
    expect($order->branch_id)->toBe($this->sandton)->and($order->subtotal_cents)->toBe(54000) // R270 each in Sandton
        ->and(($this->at)($this->sandton))->toBe(1)->and(($this->at)($this->main))->toBe(1)->and(($this->in)(fn () => $this->tee->refresh()->stock))->toBe(2);
    expect(fn () => ($this->order)($this->main, 2))->toThrow(CheckoutProblem::class, 'Only 1 of Kasi T-shirt left at this branch — please change the quantity.');

    ($this->in)(fn () => app(OrderManager::class)->cancel($order->refresh(), 'Thabo'));
    expect(($this->at)($this->sandton))->toBe(3)->and(($this->at)($this->main))->toBe(1);
});

it('lists branches to collect from, with each branch’s stock and price', function () {
    $site = 'http://kasi-threads.'.config('wurk.domains.root');
    $this->postJson("{$site}/cart/price", ['lines' => [['variant' => $this->tee->id, 'quantity' => 1]], 'branch' => $this->sandton])
        ->assertOk()->assertJsonCount(2, 'checkout.branches')->assertJsonPath('checkout.branch', $this->sandton)
        ->assertJsonPath('lines.0.available', 3)->assertJsonPath('lines.0.unit_cents', 27000);
});

it('shows a branch manager only their branch’s orders, and not the products', function () {
    ($this->order)($this->main, 1);
    ($this->order)($this->sandton, 1);
    $manager = $this->person(['email' => 'musa@kasi.co.za', 'phone' => '+27820000003']);
    $this->member($manager, $this->shop);
    ($this->in)(fn () => app(Team::class)->setBranches(app(Team::class)->add($manager->id, 'Musa', 'branch-manager'), [$this->sandton]));

    $this->actingAs($manager)->get($this->app('/kasi-threads/shop/orders?show=all'))->assertInertia(fn (Assert $p) => $p->component('Shop::Orders')->has('orders', 1));
    $this->actingAs($manager)->get($this->app('/kasi-threads/shop/products'))->assertNotFound(); // products are business-wide
    ($this->in)(fn () => expect(Order::query()->withoutGlobalScope('branch')->count())->toBe(2));
});

it('alerts a branch manager only about their branch’s orders (owners about all)', function () {
    Mail::fake();
    $owner = $this->person(['email' => 'owner@kasi.co.za', 'phone' => '+27820000004']);
    $manager = $this->person(['email' => 'musa@kasi.co.za', 'phone' => '+27820000003']);
    foreach ([$owner, $manager] as $who) {
        $this->member($who, $this->shop);
    }
    ($this->in)(function () use ($owner, $manager) {
        app(Team::class)->add($owner->id, 'Thandi', 'owner');
        app(Team::class)->setBranches(app(Team::class)->add($manager->id, 'Musa', 'branch-manager'), [$this->sandton]);
    });
    $to = function (string $branch) {
        Mail::fake();
        ($this->in)(fn () => app(OrderMessages::class)->newOrder(($this->order)($branch, 1)));

        return Mail::sent(NewOrderMail::class)->flatMap(fn ($m) => array_column($m->to, 'address'))->sort()->values()->all();
    };
    expect($to($this->sandton))->toBe(['musa@kasi.co.za', 'owner@kasi.co.za'])->and($to($this->main))->toBe(['owner@kasi.co.za']);
});

it('records what one branch owes another, nets refunds, and settles a month by hand', function () {
    // Sandton has no EFT of its own, so the customer pays into the business's (= the main branch's) account.
    $sandtonOrder = ($this->order)($this->sandton, 1); // R270 at Sandton
    $mainOrder = ($this->order)($this->main, 1);
    ($this->in)(function () use ($sandtonOrder, $mainOrder) {
        foreach ([$sandtonOrder, $mainOrder] as $o) {
            app(OrderManager::class)->advance($o->refresh(), 'paid', 'Thabo');
        }
        app(OrderManager::class)->refund($sandtonOrder->refresh(), 5000, false, 'Thabo');
    });
    $statement = ($this->in)(fn () => app(Settlements::class)->statement(now()->format('Y-m')));
    expect($statement)->toHaveCount(1) // the main-branch order owes nobody
        ->and($statement[0])->toMatchArray(['from' => $this->main, 'to' => $this->sandton, 'total_cents' => 22000, 'settled' => false])
        ->and(array_column($statement[0]['entries'], 'amount_cents'))->toBe([27000, -5000]);

    ($this->in)(fn () => expect(app(Settlements::class)->settle(now()->format('Y-m'), $this->main, $this->sandton, 'Thandi'))->toBe(2)
        ->and(app(Settlements::class)->statement(now()->format('Y-m'))[0]['settled'])->toBeTrue());
});

it('shows the owner each month’s statement, with branch names, and settles it', function () {
    $owner = $this->person(['email' => 'owner@kasi.co.za', 'phone' => '+27820000004']);
    $this->member($owner, $this->shop);
    ($this->in)(function () use ($owner) {
        app(Team::class)->add($owner->id, 'Thandi', 'owner');
        app(OrderManager::class)->advance(($this->order)($this->sandton, 1)->refresh(), 'paid', 'Thabo');
    });
    $mainName = ($this->in)(fn () => app(BranchDirectory::class)->open()[0]['name']); // the main branch is listed first
    $base = $this->app('/kasi-threads/settings/branches/settlements');
    $this->actingAs($owner)->get($base)->assertInertia(fn (Assert $p) => $p->component('Team::Settlements')
        ->where('pairs.0.from_name', $mainName)->where('pairs.0.to_name', 'Sandton')->where('pairs.0.total_cents', 27000)->where('canSettle', true));
    $this->actingAs($owner)->post("{$base}/settle", ['month' => now()->format('Y-m'), 'from' => $this->main, 'to' => $this->sandton])->assertSessionHas('status', 'Marked as settled.');
    $this->actingAs($owner)->get($base)->assertInertia(fn (Assert $p) => $p->where('pairs.0.settled', true));
});

it('makes one contact of someone who orders twice, however they write their number', function () {
    ($this->in)(function () {
        app(OrderPlacer::class)->place([['variant' => $this->tee->id, 'quantity' => 1]], ['name' => 'Sipho', 'phone' => '082 555 0101'], 'collection', 'offline', $this->main);
        app(OrderPlacer::class)->place([['variant' => $this->tee->id, 'quantity' => 1]], ['name' => 'Sipho M', 'phone' => '+27 82 555 0101'], 'collection', 'offline', $this->sandton);
        $db = DB::connection(app(TenantContext::class)->connectionName());
        expect($db->table('contacts')->count())->toBe(1)->and($db->table('contacts')->value('phone'))->toBe('+27825550101')
            ->and($db->table('contact_links')->where('source_type', 'order')->count())->toBe(2)
            ->and($db->table('contact_links')->pluck('branch_id')->sort()->values()->all())->toBe(collect([$this->main, $this->sandton])->sort()->values()->all());
    });
});

it('makes contacts of customers from before contacts existed, and running it again changes nothing', function () {
    ($this->in)(function () {
        Order::withoutEvents(function () { // as if placed before contacts existed
            foreach ([['Sipho', '082 555 0101', 1], ['Sipho M', '+27825550101', 2], ['Lerato', '083 000 0000', 3]] as [$name, $phone, $n]) {
                Order::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'number' => 900 + $n, 'branch_id' => $this->main, 'status' => 'done', 'payment_method' => 'offline', 'fulfilment' => 'collection',
                    'customer_name' => $name, 'customer_phone' => $phone, 'subtotal_cents' => 1000, 'delivery_cents' => 0, 'total_cents' => 1000]);
            }
        });
    });
    $count = fn () => ($this->in)(fn () => [DB::connection(app(TenantContext::class)->connectionName())->table('contacts')->count(),
        DB::connection(app(TenantContext::class)->connectionName())->table('contact_links')->count()]);
    expect($count())->toBe([0, 0]);
    $this->artisan('crm:link-existing')->assertSuccessful();
    expect($count())->toBe([2, 3]); // Sipho (two orders) and Lerato
    $this->artisan('crm:link-existing')->assertSuccessful();
    expect($count())->toBe([2, 3]);
});
