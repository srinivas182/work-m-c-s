<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Portal\Support\Handoff;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->useFakeOtpChannel();
    $this->createShards(1); // both businesses on the same shard
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->kasi = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->other = $this->createTenant('other-shop', name: 'Other Shop');
    $this->ctx = app(TenantContext::class);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'crm', 'referrals'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->ctx->run($this->glow, function () {
        ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 1000, 'level_bp' => [], 'attribution' => 'first', 'link_days' => 30]);
        $book = app(ContactBook::class);
        $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'order', '01JD00000000000000000ORD01', null, 'Order #1001 · R500');
        event(new PurchaseMade('order', '01JD00000000000000000ORD01', 50000, 50000, null, now()->subDay()));
        $later = (string) Str::ulid();
        $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'order', $later, null, 'Order #1002');
        event(new PurchaseMade('order', $later, 20000, 20000, null, now()->addDays(10)));
    });
    $this->ctx->run($this->kasi, fn () => app(ContactBook::class)->remember('Sipho Ndlovu', null, 'sipho@example.com', 'sale', '01JD00000000000000000SAL01', null, 'In-store sale: R120.00'));
    $this->site = fn (string $h) => 'http://'.$h.'.'.config('wurk.domains.root').'/account';
    // Sipho signs in to both businesses' portals (that's what puts them in My WURK).
    foreach (['glow-salon', 'kasi-threads'] as $h) {
        $this->post(($this->site)($h).'/code', ['contact' => 'sipho@example.com']);
        $this->post(($this->site)($h).'/verify', ['code' => FakeOtpChannel::$codes['sipho@example.com']]);
        $this->post(($this->site)($h).'/consent', ['agree' => '1']);
        $this->travel(1)->minutes(); // codes to one address are spaced
    }
    auth()->logout();
    $this->my = 'http://'.config('wurk.domains.my');
    $this->signIn = function () {
        $this->post("{$this->my}/code", ['contact' => 'sipho@example.com'])->assertSessionHas('status');
        $this->post("{$this->my}/verify", ['code' => FakeOtpChannel::$codes['sipho@example.com']])->assertRedirect('/');
    };
    $this->sipho = (string) DB::table('identities')->where('email', 'sipho@example.com')->value('id');
});

it('signs in existing WURK IDs only, and shows one card per business — each from that business alone', function () {
    $this->get($this->my)->assertInertia(fn (Assert $p) => $p->component('Portal::MyWurk/SignIn'));
    FakeOtpChannel::reset();
    $this->post("{$this->my}/code", ['contact' => 'nobody@example.com'])->assertSessionHas('status'); // same answer…
    expect(FakeOtpChannel::$codes)->toBe([])                                                           // …but no code
        ->and(DB::table('identities')->where('email', 'nobody@example.com')->exists())->toBeFalse();   // and no WURK ID made

    ($this->signIn)();
    $this->get($this->my)->assertInertia(fn (Assert $p) => $p->component('Portal::MyWurk/Home')
        ->has('cards', 2)
        ->where('cards.0.business', 'Glow Salon')->where('cards.0.wallet_cents', 5000)->where('cards.0.pending_cents', 2000)->where('cards.0.latest', fn ($l) => str_starts_with((string) $l, 'Order #')) // Glow's own order (both are from the same second)
        ->where('cards.1.business', 'Kasi Threads')->where('cards.1.wallet_cents', null)->where('cards.1.latest', fn ($l) => str_starts_with((string) $l, 'In-store sale')));
});

it('opens a business’s portal already signed in, with a pass that works once, there only, for a minute', function () {
    ($this->signIn)();
    $go = $this->get("{$this->my}/open/glow-salon")->assertRedirect();
    $url = (string) $go->headers->get('Location');
    expect($url)->toStartWith(($this->site)('glow-salon').'/handoff?pass=');
    $pass = urldecode(substr($url, strpos($url, 'pass=') + 5));
    auth()->logout();
    $this->get($url)->assertRedirect();
    $this->get(($this->site)('glow-salon'))->assertInertia(fn (Assert $p) => $p->component('Portal::Home'));

    auth()->logout();
    $this->get($url);                                                                      // the same pass again
    $this->get(($this->site)('glow-salon'))->assertInertia(fn (Assert $p) => $p->component('Portal::SignIn'));
    $fresh = $this->ctx->run($this->glow, fn () => app(Handoff::class)->issue($this->sipho, $this->glow->id));
    expect($this->ctx->run($this->kasi, fn () => app(Handoff::class)->redeem($fresh, $this->kasi->id)))->toBeNull(); // another business
    $this->travel(61)->seconds();
    expect(app(Handoff::class)->redeem($fresh, $this->glow->id))->toBeNull();             // expired
});

it('refuses businesses they have no account with or have hidden; the index can be rebuilt', function () {
    ($this->signIn)();
    $this->get("{$this->my}/open/other-shop")->assertNotFound();
    $this->post("{$this->my}/hide/kasi-threads")->assertSessionHas('status', 'Removed from My WURK. Your account with them is unchanged.');
    $this->get("{$this->my}/open/kasi-threads")->assertNotFound();
    $this->get($this->my)->assertInertia(fn (Assert $p) => $p->has('cards', 1)->where('cards.0.business', 'Glow Salon'));

    DB::table('customer_memberships')->delete();
    $this->artisan('portal:index-memberships')->expectsOutput('Indexed 2 portal accounts.')->assertSuccessful();
    expect(DB::table('customer_memberships')->where('identity_id', $this->sipho)->count())->toBe(2);
});

it('never treats a reserved address as a business site', function () {
    $this->get('http://my.'.config('wurk.domains.root').'/r/ABCDEFG')->assertNotFound();               // not a referral link for a business "my"
    $this->get('http://admin.'.config('wurk.domains.root').'/cart/checkout')->assertNotFound();         // nor a shop
    $this->get(($this->site)('glow-salon'))->assertOk();                                                // real businesses unaffected
});
