<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Support\ReturnWindow;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Attribution;
use Wurk\Referrals\Support\Commissions;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\OrderManager;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Across Shop, CRM and Referrals: a visitor follows a referral link, checks out a real order (EFT), the owner marks
 * it paid — the buyer and their referrer earn; a refund reverses it. Shop knows nothing about referrals.
 */
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
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop', 'referrals'],
    ], null);
    app(Catalog::class)->subscribe($this->shop->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    [$this->tee, $this->thandi] = ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'collection_address' => '12 Vilakazi St, Soweto']);
        app(BusinessPayments::class)->setUpEft("FNB Kasi Threads\nAccount 6200 0000 000, branch 250655");
        ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 200, 'level_bp' => [1000], 'attribution' => 'first', 'link_days' => 30]);
        $tee = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'published', 'options' => []]);
        $thandi = app(ContactBook::class)->remember('Thandi', '082 000 0001', null, 'test', 'seed', null, 'Seed');

        return [Variant::query()->create(['product_id' => $tee->id, 'price_cents' => 25000, 'stock' => 5]), app(Attribution::class)->member($thandi)];
    });
    $this->site = 'http://kasi-threads.'.config('wurk.domains.root');
});

it('rewards the buyer and the person who referred them when a real order is paid, and reverses it on refund', function () {
    $link = $this->get("{$this->site}/r/{$this->thandi->code}")->assertRedirect('/');
    $code = collect($link->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'wurk_ref')->getValue();
    // withCredentials: the test client only sends cookies with JSON requests when asked (browsers send same-site cookies with fetch).
    $this->withCredentials()->withUnencryptedCookie('wurk_ref', $code)->postJson("{$this->site}/cart/checkout", [
        'lines' => [['variant' => $this->tee->id, 'quantity' => 2]], 'name' => 'Sipho', 'phone' => '082 555 0101', 'fulfilment' => 'collection', 'consent' => '1',
    ])->assertCreated();

    ($this->in)(function () {
        $order = Order::query()->sole();
        $sipho = ReferralMember::query()->where('id', '!=', $this->thandi->id)->sole();
        expect($sipho->referrer_id)->toBe($this->thandi->id);
        expect(app(Commissions::class)->balance($this->thandi->id)['pending'])->toBe(0); // not paid yet: nothing earned

        app(OrderManager::class)->advance($order, 'paid', 'Thabo');                      // the owner confirms the EFT
        expect(app(Commissions::class)->balance($sipho->id))->toBe(['pending' => 1000, 'available' => 0])          // 2% of R500
            ->and(app(Commissions::class)->balance($this->thandi->id))->toBe(['pending' => 5000, 'available' => 0]); // 10% of R500

        app(OrderManager::class)->refund($order->refresh(), 25000, true, 'Thabo', 'One returned'); // half
        expect(app(Commissions::class)->balance($this->thandi->id)['pending'])->toBe(2500);
    });
});

it('makes rewards available when the business’s own return window ends', function () {
    ($this->in)(fn () => app(ReturnWindow::class)->set(7));
    $this->postJson("{$this->site}/cart/checkout", ['lines' => [['variant' => $this->tee->id, 'quantity' => 1]], 'name' => 'Thandi', 'phone' => '082 000 0001', 'fulfilment' => 'collection', 'consent' => '1'])->assertCreated();
    ($this->in)(fn () => app(OrderManager::class)->advance(Order::query()->sole(), 'paid', 'Thabo'));
    $this->travel(6)->days();
    expect(($this->in)(fn () => app(Commissions::class)->balance($this->thandi->id)))->toBe(['pending' => 500, 'available' => 0]); // 2% of R250, still refundable
    $this->travel(2)->days();
    expect(($this->in)(fn () => app(Commissions::class)->balance($this->thandi->id)))->toBe(['pending' => 0, 'available' => 500]); // the window has closed
});
