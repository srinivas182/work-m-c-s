<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Contracts\StoreCredit;
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
use Wurk\Shop\Support\OrderPlacer;
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

describe('paying from the wallet', function () {
    beforeEach(function () {
        // Thandi has R50 available: an earlier R500 purchase at 10%, already past its return window.
        ($this->in)(function () {
            ReferralProgramme::query()->update(['self_bp' => 1000, 'max_wallet_bp' => 5000]);
            $old = (string) Str::ulid();
            app(ContactBook::class)->remember('Thandi', '082 000 0001', null, 'order', $old, null, 'Order');
            event(new PurchaseMade('order', $old, 50000, 50000, null, now()->subDay()));
        });
        $this->pass = ($this->in)(fn () => app(StoreCredit::class)->passFor($this->thandi->contact_id));
        $this->checkout = fn (array $wallet, int $qty = 1) => $this->postJson("{$this->site}/cart/checkout", ['lines' => [['variant' => $this->tee->id, 'quantity' => $qty]],
            'name' => 'Thandi', 'phone' => '082 000 0001', 'fulfilment' => 'collection', 'consent' => '1', 'wallet' => $wallet]);
        $this->wallet = fn () => ($this->in)(fn () => app(StoreCredit::class)->available($this->thandi->contact_id));
    });

    it('takes the wallet part off what’s due, still rewards the full order, and splits refunds exactly', function () {
        ($this->checkout)(['pass' => $this->pass, 'cents' => 5000])->assertCreated();
        expect(($this->wallet)())->toBe(0);
        ($this->in)(function () {
            $order = Order::query()->sole();
            expect($order->wallet_cents)->toBe(5000)->and($order->dueCents())->toBe(20000);
            app(OrderManager::class)->advance($order, 'paid', 'Thabo');
            expect(app(Commissions::class)->balance($this->thandi->id)['pending'])->toBe(2500);        // 10% of the full R250 (decision E)
            app(OrderManager::class)->refund($order->refresh(), 10000, false, 'Thabo');               // R100: R20 to the wallet, R80 cash
            expect($order->refresh()->wallet_refunded_cents)->toBe(2000);
            app(OrderManager::class)->refund($order->refresh(), null, false, 'Thabo');                // the rest: R30 wallet, R120 cash
            expect($order->refresh()->wallet_refunded_cents)->toBe(5000)->and($order->refunded_cents)->toBe(25000);
        });
        expect(($this->wallet)())->toBe(5000); // all of it back
    });

    it('refuses more than the rules allow (stock returned) and forged passes; gives the wallet part back if the order expires unpaid', function () {
        ($this->checkout)(['pass' => $this->pass, 'cents' => 6000])->assertStatus(409)->assertJson(['message' => 'Only R50.00 of the wallet can be used on this order.']);
        ($this->checkout)(['pass' => $this->pass.'x', 'cents' => 1000])->assertStatus(409)->assertJson(['message' => 'Your wallet couldn’t be used — sign in to your account again and retry.']);
        expect(($this->in)(fn () => Order::query()->where('status', 'cancelled')->count()))->toBe(2)
            ->and(($this->in)(fn () => $this->tee->refresh()->stock))->toBe(5) // stock given back
            ->and(($this->wallet)())->toBe(5000);

        ($this->checkout)(['pass' => $this->pass, 'cents' => 3000])->assertCreated();
        expect(($this->wallet)())->toBe(2000);
        ($this->in)(fn () => app(OrderPlacer::class)->release(Order::query()->where('status', 'awaiting_payment')->sole(), 'expired', null));
        expect(($this->wallet)())->toBe(5000);
    });

    it('tells the checkout how much of the wallet this order may use (the business’s share rule)', function () {
        ($this->in)(function () {
            $credit = app(StoreCredit::class);
            expect($credit->usableFor($this->thandi->contact_id, 25000))->toBe(5000)   // balance R50 < 50% of R250
                ->and($credit->usableFor($this->thandi->contact_id, 6000))->toBe(3000); // 50% of R60
        });
    });

    it('pays the whole order from the wallet when the business allows it', function () {
        ($this->in)(fn () => ReferralProgramme::query()->update(['max_wallet_bp' => 10000]));
        ($this->in)(fn () => $this->tee->update(['price_cents' => 4000]));
        ($this->checkout)(['pass' => $this->pass, 'cents' => 4000])->assertCreated()->assertJson(['due_cents' => 0]);
        expect(($this->in)(fn () => Order::query()->sole()->status))->toBe('paid')->and(($this->wallet)())->toBe(1000);
    });
});
