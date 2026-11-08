<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Contracts\PurchaseRefunded;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Attribution;
use Wurk\Referrals\Support\Commissions;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1); // both businesses on the same shard
    $this->ctx = app(TenantContext::class);
    $this->in = fn ($t, callable $fn) => $this->ctx->run($t, $fn);
    $this->glow = $this->createTenant('glow-salon');
    $this->kasi = $this->createTenant('kasi-threads');
    $this->package = function ($t, int $levels, int $cap, bool $change = false) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.Str::lower((string) Str::ulid()), 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => $levels,
            'referral_max_commission_bp' => $cap, 'managed_fee_cents' => null, 'modules' => ['website', 'shop', 'referrals'],
        ], null);
        $change ? app(Catalog::class)->changePackage($t->id, $p->id, 'monthly') : app(Catalog::class)->subscribe($t->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    ($this->package)($this->glow, 3, 1500);
    ($this->in)($this->glow, fn () => ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 200, 'level_bp' => [500, 300, 100], 'attribution' => 'first', 'link_days' => 30]));
    $this->customer = fn (string $name, string $phone, ?ReferralMember $by = null) => ($this->in)($this->glow, function () use ($name, $phone, $by) {
        $id = app(ContactBook::class)->remember($name, $phone, null, 'test', (string) Str::ulid(), null, 'Test');
        app(Attribution::class)->attach($id, $by?->code);

        return ReferralMember::query()->where('contact_id', $id)->sole();
    });
    // Ayanda ← Bongi ← Chris ← Dineo (the buyer).
    $this->a = ($this->customer)('Ayanda', '082 000 0001');
    $this->b = ($this->customer)('Bongi', '082 000 0002', $this->a);
    $this->c = ($this->customer)('Chris', '082 000 0003', $this->b);
    $this->d = ($this->customer)('Dineo', '082 000 0004', $this->c);
    $this->orderId = (string) Str::ulid();
    ($this->in)($this->glow, fn () => app(ContactBook::class)->remember('Dineo', '082 000 0004', null, 'order', $this->orderId, null, 'Order'));
    $this->buy = fn (int $paid = 109999, int $items = 99999) => ($this->in)($this->glow, fn () => event(new PurchaseMade('order', $this->orderId, $paid, $items, null, now()->addDays(14))));
    $this->balance = fn (ReferralMember $m) => ($this->in)($this->glow, fn () => app(Commissions::class)->balance($m->id));
});

it('rewards the buyer and each referrer on the items (not delivery), pending until the return window ends — once', function () {
    ($this->buy)();
    ($this->buy)(); // a resent payment notice
    // R999.99 of items: 2% → 1999c, 5% → 4999c, 3% → 2999c, 1% → 999c (rounded down).
    expect(($this->balance)($this->d))->toBe(['pending' => 1999, 'available' => 0])
        ->and(($this->balance)($this->c)['pending'])->toBe(4999)->and(($this->balance)($this->b)['pending'])->toBe(2999)->and(($this->balance)($this->a)['pending'])->toBe(999);
    $this->travel(15)->days();
    expect(($this->balance)($this->c))->toBe(['pending' => 0, 'available' => 4999]);
});

it('reverses rewards in proportion to refunds, and a full refund reverses exactly what’s left', function () {
    ($this->buy)();
    ($this->in)($this->glow, fn () => event(new PurchaseRefunded('order', $this->orderId, 36666))); // a third of 109999
    expect(($this->balance)($this->c))->toBe(['pending' => 4999 - intdiv(4999 * 36666, 109999), 'available' => 0]); // 3333: the pending reward reduced
    ($this->in)($this->glow, fn () => event(new PurchaseRefunded('order', $this->orderId, 73333))); // the rest
    foreach ([$this->a, $this->b, $this->c, $this->d] as $m) {
        expect(array_sum(($this->balance)($m)))->toBe(0); // exactly nothing left — no rounding crumbs
    }
    $lines = ($this->in)($this->glow, fn () => DB::connection($this->ctx->connectionName())->table('referral_ledger')->where('tenant_id', $this->ctx->id())->where('kind', 'earned')->count());
    expect($lines)->toBe(4); // the earned lines are never edited — reversals are added
});

it('applies the package’s current limits, and does nothing when the programme is off or no customer is linked', function () {
    ($this->package)($this->glow, 2, 600, true); // downgraded: 2 levels, 6% in total
    ($this->buy)();
    expect(($this->balance)($this->d)['pending'])->toBe(1999)      // 2%
        ->and(($this->balance)($this->c)['pending'])->toBe(3999)   // 5% → clamped to the 4% left
        ->and(($this->balance)($this->b)['pending'])->toBe(0)      // nothing left in the budget
        ->and(($this->balance)($this->a)['pending'])->toBe(0);     // beyond 2 levels

    ($this->in)($this->glow, fn () => event(new PurchaseMade('order', (string) Str::ulid(), 50000, 50000, null, now()->addDays(14)))); // not linked to a customer
    ($this->in)($this->glow, fn () => ReferralProgramme::query()->update(['enabled' => false]));
    $other = (string) Str::ulid();
    ($this->in)($this->glow, fn () => app(ContactBook::class)->remember('Dineo', '082 000 0004', null, 'order', $other, null, 'Order'));
    ($this->in)($this->glow, fn () => event(new PurchaseMade('order', $other, 50000, 50000, null, now()->addDays(14))));
    expect(($this->balance)($this->d)['pending'])->toBe(1999); // unchanged
    expect(($this->in)($this->kasi, fn () => DB::connection($this->ctx->connectionName())->table('referral_ledger')->where('tenant_id', $this->ctx->id())->count()))->toBe(0);
});

it('reduces what’s already available when a reward is refunded after the return window', function () {
    ($this->buy)();
    $this->travel(15)->days();
    ($this->in)($this->glow, fn () => event(new PurchaseRefunded('order', $this->orderId, 109999)));
    expect(($this->balance)($this->c))->toBe(['pending' => 0, 'available' => 0]);
});
