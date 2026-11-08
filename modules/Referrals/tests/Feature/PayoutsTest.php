<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Identity\Models\Identity;
use Wurk\Messaging\Mail\CustomerMessageMail;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralPayout;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->useFakeOtpChannel();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'crm', 'referrals'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($this->owner, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Thabo', 'owner'));
    // Sipho has R150 available at a business that pays out from R100.
    $this->sipho = ($this->in)(function () {
        ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 1000, 'level_bp' => [], 'attribution' => 'first', 'link_days' => 30,
            'payouts_allowed' => true, 'min_payout_cents' => 10000]);
        $id = app(ContactBook::class)->remember('Sipho Ndlovu', null, 'sipho@example.com', 'order', '01JD00000000000000000ORD01', null, 'Order #1001');
        event(new PurchaseMade('order', '01JD00000000000000000ORD01', 150000, 150000, null, now()->subDay()));

        return $id;
    });
    $this->site = 'http://glow-salon.'.config('wurk.domains.root').'/account';
    $this->post("{$this->site}/code", ['contact' => 'sipho@example.com']);
    $this->post("{$this->site}/verify", ['code' => FakeOtpChannel::$codes['sipho@example.com']]);
    $this->post("{$this->site}/consent", ['agree' => '1']);
    $this->siphoId = Identity::query()->where('email', 'sipho@example.com')->value('id');
    $this->bank = ['holder' => 'Sipho Ndlovu', 'bank' => 'Capitec', 'account' => '1234 5678 90', 'branch' => '470010'];
    $this->url = $this->app('/glow-salon/payouts');
    $this->wallet = fn () => ($this->in)(fn () => app(StoreCredit::class)->available($this->sipho));
});

it('reserves the amount at once, pays out with a reference, and deletes the account number afterwards', function () {
    $this->get($this->site)->assertInertia(fn (Assert $p) => $p->where('payouts.allowed', true)->where('payouts.min_cents', 10000)->where('payouts.wallet_cents', 15000));
    $this->post("{$this->site}/payout", [...$this->bank, 'amount' => '50'])->assertSessionHasErrors(['amount' => 'Choose an amount from R100.00 to R150.00.']);
    $this->post("{$this->site}/payout", [...$this->bank, 'amount' => '200'])->assertSessionHasErrors('amount');
    $this->post("{$this->site}/payout", [...$this->bank, 'amount' => '150'])->assertSessionHas('status', 'Payout requested — the business will pay it into your account.');
    expect(($this->wallet)())->toBe(0); // reserved: can't also be spent at checkout
    $this->post("{$this->site}/payout", [...$this->bank, 'amount' => '150'])->assertSessionHasErrors('amount');

    ($this->in)(function () {
        $raw = DB::connection($this->ctx->connectionName())->table('referral_payouts')->where('tenant_id', $this->ctx->id())->value('account_number');
        expect($raw)->not->toContain('1234567890')                                        // encrypted at rest
            ->and(ReferralPayout::query()->sole()->account_number)->toBe('1234567890');
    });
    $this->actingAs($this->owner)->get($this->url)->assertInertia(fn (Assert $p) => $p->component('Referrals::Payouts')->where('payouts.0.account', '1234567890')->where('payouts.0.cents', 15000));
    $id = ($this->in)(fn () => ReferralPayout::query()->sole()->id);
    $this->actingAs($this->owner)->post("{$this->url}/{$id}/paid", [])->assertSessionHasErrors(['reference' => 'Add your payment reference.']);
    $this->actingAs($this->owner)->post("{$this->url}/{$id}/paid", ['reference' => 'GLOW-0042'])->assertSessionHas('status', 'Marked paid — the customer has been told.');

    ($this->in)(function () {
        $p = ReferralPayout::query()->sole();
        expect([$p->status, $p->account_number, $p->account_last4])->toBe(['paid', null, '7890']);
        $log = AuditEvent::query()->where('action', 'like', 'referrals.payout%')->get()->map(fn ($e) => $e->getAttributes());
        expect($log->pluck('action')->all())->toContain('referrals.payout_requested', 'referrals.payout_paid')
            ->and(json_encode($log->all()))->not->toContain('1234567890')->not->toContain('Sipho');
    });
    $this->actingAs($this->owner)->get($this->url)->assertInertia(fn (Assert $p) => $p->where('payouts.0.account', '•••• 7890'));
    Mail::assertSent(CustomerMessageMail::class, fn ($m) => $m->hasTo('sipho@example.com') && str_contains($m->body, 'GLOW-0042') && str_contains($m->body, 'R150.00'));
    expect(($this->wallet)())->toBe(0);
});

it('declines with a reason, returning the amount to the wallet; refuses payouts where the business doesn’t allow them', function () {
    $this->post("{$this->site}/payout", [...$this->bank, 'amount' => '120']);
    $id = ($this->in)(fn () => ReferralPayout::query()->sole()->id);
    $this->actingAs($this->owner)->post("{$this->url}/{$id}/decline", ['reason' => 'the account number didn’t match the name.'])
        ->assertSessionHas('status', 'Declined — the amount is back in their wallet and they’ve been told why.');
    expect(($this->wallet)())->toBe(15000)->and(($this->in)(fn () => ReferralPayout::query()->sole()->account_number))->toBeNull();
    Mail::assertSent(CustomerMessageMail::class, fn ($m) => $m->hasTo('sipho@example.com') && str_contains($m->body, 'didn’t match the name'));
    $this->actingAs(Identity::query()->findOrFail($this->siphoId))->get($this->site)->assertInertia(fn (Assert $p) => $p->where('payouts.latest.status', 'declined'));
    $this->actingAs($this->owner)->post("{$this->url}/{$id}/paid", ['reference' => 'X'])->assertSessionHasErrors(['payout' => 'This payout has already been handled.']);

    ($this->in)(fn () => ReferralProgramme::query()->update(['payouts_allowed' => false]));
    $this->actingAs(Identity::query()->findOrFail($this->siphoId))->post("{$this->site}/payout", [...$this->bank, 'amount' => '120'])
        ->assertSessionHasErrors(['amount' => 'This business doesn’t pay out rewards — spend them with them instead.']);
});
