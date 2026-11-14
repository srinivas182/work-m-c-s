<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\SupportSessions;
use Wurk\Support\Mail\TicketMail;
use Wurk\Support\Models\SupportAccessGrant;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'marketing', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->ctx = app(TenantContext::class);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->member($this->staff, $this->glow);
    $this->conn = $this->ctx->run($this->glow, function () {
        app(Team::class)->add($this->owner->id, 'Thandi', 'owner');
        app(Team::class)->add($this->staff->id, 'Sam', 'staff');

        return $this->ctx->connectionName();
    });
    $this->agent = $this->staffWithRole('support', 'help@wurk.co.za');
    $this->back = fn (string $path = '') => $this->app('/glow-salon'.$path);
    $this->actingAs($this->owner)->post(($this->back)('/support'), ['subject' => 'Coupons not applying', 'body' => 'Customers say the code doesn’t work.']);
    $this->ask = fn (string $level = 'view') => $this->actingAs($this->agent)->post($this->admin('/support/1001/access'), ['level' => $level, 'reason' => 'To see how your coupon is set up.']);
    $this->approve = function (string $level = 'view', int $minutes = 60) {
        $g = SupportAccessGrant::query()->where('status', 'requested')->orderByDesc('id')->firstOrFail();
        $this->actingAs($this->owner)->post(($this->back)("/support/access/{$g->id}/approve"), ['level' => $level, 'minutes' => $minutes]);

        return $g->refresh();
    };
});

it('lets WURK in only when the owner approves — asked from the ticket, told by email and banner', function () {
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertNotFound(); // no access yet
    ($this->ask)()->assertSessionHas('status', 'Asked. The business’s owners have been told; you’ll be emailed when they decide.');
    Mail::assertSent(TicketMail::class, fn (TicketMail $m) => $m->hasTo('owner@glow.co.za') && $m->subjectLine === 'WURK support asks for view-only access (#1001)');
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertNotFound(); // still not until approved

    // The owner sees a banner and the request; staff without the permission don't.
    $this->actingAs($this->owner)->get(($this->back)('/support'))->assertInertia(fn (Assert $p) => $p->where('banner.message', 'WURK support asks for view-only access to help with your ticket.'));
    $this->actingAs($this->staff)->get(($this->back)('/support/access'))->assertNotFound();
    $this->actingAs($this->owner)->get(($this->back)('/support/access'))->assertInertia(fn (Assert $p) => $p->component('Support::Access')
        ->where('grants.0.status', 'requested')->where('grants.0.reason', 'To see how your coupon is set up.'));
    $this->actingAs($this->owner)->get(($this->back)('/support/1001'))->assertInertia(fn (Assert $p) => $p->where('messages.1.from', 'WURK support'));

    $g = ($this->approve)('view', 30);
    expect($g->status)->toBe('active')->and($g->level)->toBe('view')->and((int) round(now()->diffInMinutes($g->ends_at)))->toBe(30);
    Mail::assertSent(TicketMail::class, fn (TicketMail $m) => $m->hasTo('help@wurk.co.za') && $m->subjectLine === 'Access approved: Glow Salon');
    $this->actingAs($this->owner)->get(($this->back)('/support'))->assertInertia(fn (Assert $p) => $p->where('banner.tone', 'warning')->where('banner.action.label', 'End access'));

    // WURK is in, and sees whose back office it is.
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('banner.action.label', 'Back to ticket #1001')->where('banner.tone', 'warning'));
    $this->actingAs($this->agent)->get(($this->back)('/'))->assertOk(); // the workspace home too
    $this->actingAs($this->agent)->get($this->admin('/support/1001'))->assertInertia(fn (Assert $p) => $p->where('access.status', 'active')->where('access.mine', true));
});

it('keeps view-only access read-only, and full access away from money, access, security and health records', function () {
    ($this->ask)();
    ($this->approve)('view');
    $coupon = ['code' => 'SUPPORT10', 'kind' => 'percent', 'value' => 10, 'applies_to' => 'both'];
    $this->actingAs($this->agent)->post(($this->back)('/coupons'), $coupon)->assertForbidden(); // view-only: no changes
    $this->actingAs($this->agent)->get(($this->back)('/records'))->assertNotFound();          // health records: never

    $g = SupportAccessGrant::query()->firstOrFail();
    $this->actingAs($this->owner)->post(($this->back)("/support/access/{$g->id}/end"))->assertSessionHas('status', 'Ended. WURK support no longer has access.');
    ($this->ask)('full');
    ($this->approve)('full', 120);
    $this->actingAs($this->agent)->post(($this->back)('/coupons'), $coupon)->assertSessionHas('status', 'Coupon SUPPORT10 is ready.');
    $this->actingAs($this->agent)->put(($this->back)('/settings/payments/refunds'), ['refunds' => true])->assertForbidden();   // money
    $this->actingAs($this->agent)->post(($this->back)('/settings/team/invitations'), ['email' => 'x@y.co'])->assertForbidden(); // who has access
    $this->actingAs($this->agent)->put(($this->back)('/settings/security'), [])->assertForbidden();                         // security
    $this->actingAs($this->agent)->get(($this->back)('/records'))->assertNotFound();

    // Audited as WURK staff, on behalf of the business — never as the business.
    $e = DB::connection($this->conn)->table('audit_events')->where('action', 'campaigns.coupon_created')->first();
    expect($e->actor_name)->toBe('WURK staff: Thandi Mokoena (on behalf of Glow Salon)')
        ->and($e->actor_type)->toBe('support')->and($e->impersonator_identity_id)->toBe($this->agent->id)->and($e->actor_identity_id)->toBe($this->agent->id);
});

it('ends at once when the owner says so, when time runs out, or when WURK is done', function () {
    ($this->ask)();
    $g = ($this->approve)('view', 15);
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertOk();
    $this->actingAs($this->staff)->post(($this->back)("/support/access/{$g->id}/end"))->assertNotFound(); // only those with support.access
    $this->actingAs($this->owner)->post(($this->back)("/support/access/{$g->id}/end"));
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertNotFound();
    expect(DB::connection($this->conn)->table('audit_events')->whereIn('action', ['support.access_granted', 'support.access_ended'])->count())->toBe(2);

    ($this->ask)();
    ($this->approve)('view', 60);
    $this->actingAs($this->agent)->post($this->admin('/support/1001/access/end'))->assertSessionHas('status', 'Access ended.');
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertNotFound();

    // Declined: nothing changes.
    ($this->ask)();
    $g = SupportAccessGrant::query()->where('status', 'requested')->firstOrFail();
    $this->actingAs($this->owner)->post(($this->back)("/support/access/{$g->id}/decline"))->assertSessionHas('status', 'Declined. WURK support can’t see your back office.');
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertNotFound();
    $this->actingAs($this->owner)->post(($this->back)("/support/access/{$g->id}/approve"), ['level' => 'full', 'minutes' => 60])->assertNotFound(); // decided already
    $this->actingAs($this->owner)->post(($this->back)('/support/access/'.SupportAccessGrant::query()->value('id').'/approve'), ['level' => 'full', 'minutes' => 999])->assertNotFound();

    // Time runs out.
    ($this->ask)();
    ($this->approve)('view', 15);
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertOk();
    $this->travel(16)->minutes();
    $this->actingAs($this->agent)->get(($this->back)('/campaigns'))->assertNotFound();
    expect(app(SupportSessions::class)->active($this->glow->id, $this->agent->id))->toBeNull();
});

it('refuses access longer than two hours, and other staff can’t use someone else’s access', function () {
    ($this->ask)();
    $g = SupportAccessGrant::query()->firstOrFail();
    $this->actingAs($this->owner)->post(($this->back)("/support/access/{$g->id}/approve"), ['level' => 'full', 'minutes' => 240])->assertSessionHasErrors('minutes');
    ($this->approve)('full', 120);
    $colleague = $this->staffWithRole('support', 'other@wurk.co.za');
    $this->actingAs($colleague)->get(($this->back)('/campaigns'))->assertNotFound();
});

it('never lets WURK staff see or decide on their own access request', function () {
    ($this->ask)();
    ($this->approve)('full', 60);
    ($this->ask)('full');
    $g = SupportAccessGrant::query()->where('status', 'requested')->firstOrFail();
    $this->actingAs($this->agent)->get(($this->back)('/support/access'))->assertNotFound();
    $this->actingAs($this->agent)->post(($this->back)("/support/access/{$g->id}/approve"), ['level' => 'full', 'minutes' => 120])->assertForbidden();
    expect($g->refresh()->status)->toBe('requested');
});
