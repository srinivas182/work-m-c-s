<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Models\Contact;
use Wurk\Identity\Models\Identity;
use Wurk\Messaging\Mail\CustomerMessageMail;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralMember;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Commissions;
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
    $this->site = 'http://glow-salon.'.config('wurk.domains.root').'/account';
    $this->url = $this->app('/glow-salon/data-requests');
    // Sipho: R50 available, R20 pending, an order and an enquiry, and a merged duplicate with his old phone.
    $this->sipho = ($this->in)(function () {
        ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 1000, 'level_bp' => [], 'attribution' => 'first', 'link_days' => 30]);
        $book = app(ContactBook::class);
        $id = $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'order', '01JD00000000000000000ORD01', null, 'Order #1001 · R500');
        $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'form', '01JD00000000000000000FRM01', null, 'I’d like braids on Saturday, my number is 082 999 1234');
        event(new PurchaseMade('order', '01JD00000000000000000ORD01', 50000, 50000, null, now()->subDay()));
        $later = (string) Str::ulid();
        $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'order', $later, null, 'Order #1002');
        event(new PurchaseMade('order', $later, 20000, 20000, null, now()->addDays(10)));
        Contact::query()->create(['name' => 'S. Ndlovu', 'phone' => '+27829991234', 'merged_into' => $id]);

        return $id;
    });
    // He signs in to the portal and asks for deletion.
    $this->post("{$this->site}/code", ['contact' => 'sipho@example.com']);
    $this->post("{$this->site}/verify", ['code' => FakeOtpChannel::$codes['sipho@example.com']]);
    $this->post("{$this->site}/consent", ['agree' => '1']);
    $this->post("{$this->site}/delete-request", ['confirm' => '1']);
    $this->code = ($this->in)(fn () => ReferralMember::query()->where('contact_id', $this->sipho)->sole()->code);
    $this->req = ($this->in)(fn () => (string) DB::connection($this->ctx->connectionName())->table('data_requests')->where('tenant_id', $this->ctx->id())->value('id'));
});

it('settles the wallet first, tells the customer, then erases them everywhere it should — and keeps the log free of their details', function () {
    $this->actingAs($this->owner)->get($this->url)->assertInertia(fn (Assert $p) => $p->component('Portal::DataRequests')
        ->where('requests.0.customer', 'Sipho Ndlovu')->where('requests.0.wallet_cents', 5000)->where('requests.0.pending_cents', 2000)->where('requests.0.days_open', 0));
    $this->actingAs($this->owner)->post("{$this->url}/{$this->req}/approve")->assertSessionHasErrors(['request' => 'Pay out or refund their wallet first (R50.00).']);
    $this->actingAs($this->owner)->post("{$this->url}/{$this->req}/settle", ['amount' => '50', 'method' => 'eft', 'reference' => 'FNB 44871'])
        ->assertSessionHas('status', 'Recorded: R50.00 paid to the customer.');
    $this->actingAs($this->owner)->post("{$this->url}/{$this->req}/approve")->assertSessionHas('status', 'Done — their information has been deleted and they’ve been told.');

    Mail::assertSent(CustomerMessageMail::class, fn ($m) => $m->hasTo('sipho@example.com') && str_contains($m->subjectLine, 'deleted'));
    ($this->in)(function () {
        $me = Contact::query()->find($this->sipho);
        $dup = Contact::query()->where('merged_into', $this->sipho)->sole();
        $links = DB::connection($this->ctx->connectionName())->table('contact_links')->where('tenant_id', $this->ctx->id())->pluck('summary', 'source_type');
        expect([$me->name, $me->email, $me->phone])->toBe(['Deleted customer', null, null])
            ->and([$dup->name, $dup->phone])->toBe(['Deleted customer', null])                     // the merged duplicate too
            ->and($links['form'])->toBe('Removed at the customer’s request')->and($links['order'])->toContain('Order #')
            ->and(app(StoreCredit::class)->available($this->sipho))->toBe(0)
            ->and(app(Commissions::class)->balance(ReferralMember::query()->where('contact_id', $this->sipho)->sole()->id))->toBe(['pending' => 0, 'available' => 0]);
    });
    $this->get($this->site)->assertInertia(fn (Assert $p) => $p->component('Portal::SignIn'));             // portal access ended
    $this->get('http://glow-salon.'.config('wurk.domains.root')."/r/{$this->code}")->assertCookieMissing('wurk_ref'); // link dead

    $log = ($this->in)(fn () => AuditEvent::query()->where('action', 'like', 'portal.deletion%')->orWhere('action', 'like', 'referrals.%')->get()->map(fn ($e) => $e->getAttributes()));
    expect($log->pluck('action')->all())->toContain('portal.deletion_requested', 'portal.deletion_wallet_settled', 'portal.deletion_completed', 'referrals.paid_out', 'referrals.closed')
        ->and(json_encode($log->all()))->not->toContain('Sipho')->not->toContain('sipho@example.com')->not->toContain('999'); // decision H
});

it('declines with a reason the customer is told, and shows it in their portal', function () {
    $this->actingAs($this->owner)->post("{$this->url}/{$this->req}/decline", [])->assertSessionHasErrors(['reason' => 'Tell the customer why.']);
    $this->actingAs($this->owner)->post("{$this->url}/{$this->req}/decline", ['reason' => 'you have a booking next week.'])->assertSessionHas('status', 'Declined — the customer has been told why.');
    Mail::assertSent(CustomerMessageMail::class, fn ($m) => $m->hasTo('sipho@example.com') && str_contains($m->body, 'you have a booking next week.'));
    // Back as Sipho (the test client shares one signed-in user across addresses; browsers keep a session per address).
    $sipho = Identity::query()->findOrFail(($this->in)(fn () => DB::connection($this->ctx->connectionName())->table('data_requests')->where('tenant_id', $this->ctx->id())->value('identity_id')));
    $this->actingAs($sipho)->get($this->site)->assertInertia(fn (Assert $p) => $p->where('deletion.status', 'declined')->where('deletion.reason', 'you have a booking next week.'));
    $this->actingAs($this->owner)->post("{$this->url}/{$this->req}/approve")->assertSessionHasErrors(['request' => 'This request has already been handled.']);
});

it('waits for a payout that is still being paid before deleting', function () {
    ($this->in)(fn () => ReferralProgramme::query()->update(['payouts_allowed' => true, 'min_payout_cents' => 1000]));
    $sipho = Identity::query()->findOrFail(($this->in)(fn () => DB::connection($this->ctx->connectionName())->table('data_requests')->where('tenant_id', $this->ctx->id())->value('identity_id')));
    $this->actingAs($sipho)->post("{$this->site}/payout", ['amount' => '50', 'holder' => 'Sipho Ndlovu', 'bank' => 'Capitec', 'account' => '1234567890'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post("{$this->url}/{$this->req}/approve")
        ->assertSessionHasErrors(['request' => 'They have a payout waiting — pay or decline it first (Customers → Payouts).']);
});
