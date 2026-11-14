<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\HealthSignals;
use Wurk\Success\Mail\HealthDigestMail;
use Wurk\Success\Models\BusinessHealth;
use Wurk\Success\Support\HealthScores;
use Wurk\Support\Models\SupportTicket;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function healthPackage(string $key, int $monthlyCents): string
{
    return app(PackageCatalog::class)->create([
        'key' => $key, 'name' => ucfirst($key), 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => $monthlyCents, 'yearly_price_cents' => $monthlyCents * 10, 'trial_days' => 0, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null)->id;
}

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->ctx = app(TenantContext::class);
    $growth = healthPackage('growth', 59900);
    $pro = healthPackage('pro', 119900);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');   // fine
    $this->quiet = $this->createTenant('quiet-cafe', name: 'Quiet Cafe');   // drifting away
    app(Catalog::class)->subscribe($this->glow->id, $growth, 'monthly', allowTrial: false);
    app(Catalog::class)->subscribe($this->quiet->id, $pro, 'yearly', allowTrial: false);
    app(Entitlements::class)->flush();
    $owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($owner, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($owner->id, 'Thandi', 'owner'));
    DB::table('identities')->where('id', $owner->id)->update(['last_login_at' => now()->subDay()]);
    DB::table('site_launches')->insert(['tenant_id' => $this->glow->id, 'business_created_at' => now()->subDays(40), 'launched_at' => now()->subDays(39), 'minutes' => 30]);
    DB::table('tenants')->whereIn('id', [$this->glow->id, $this->quiet->id])->update(['created_at' => now()->subDays(40)]);
    $this->staff = $this->staffWithRole('support', 'help@wurk.co.za');
});

it('scores each business from every module’s signals, with reasons and a next step', function () {
    // Quiet Cafe: nobody has signed in, never published, payment failed, unhappy with support, server errors.
    DB::table('tenant_subscriptions')->where('tenant_id', $this->quiet->id)->update(['status' => 'past_due', 'past_due_since' => now()->subDays(3)]);
    SupportTicket::query()->create(['number' => 1001, 'tenant_id' => $this->quiet->id, 'opened_by' => $this->staff->id, 'subject' => 'x', 'status' => 'solved', 'rating' => 'bad',
        'target_hours' => 8, 'first_response_due_at' => now(), 'first_responded_at' => now()]);
    DB::table('business_errors_daily')->insert(['tenant_id' => $this->quiet->id, 'day' => now()->toDateString(), 'count' => 12]);

    expect(app(HealthScores::class)->scoreAll())->toBe(2);
    $glow = BusinessHealth::query()->findOrFail($this->glow->id);
    expect($glow->score)->toBe(100)->and($glow->grade)->toBe('healthy')->and($glow->mrr_cents)->toBe(59900);

    $quiet = BusinessHealth::query()->findOrFail($this->quiet->id);
    expect($quiet->grade)->toBe('at_risk')->and($quiet->score)->toBe(0)->and($quiet->mrr_cents)->toBe(99916) // yearly ÷ 12
        ->and(array_column($quiet->signals, 'key'))->toBe(['payment_failed', 'never_signed_in', 'site_not_published', 'unhappy', 'errors'])
        ->and($quiet->signals[0])->toMatchArray(['reason' => 'Payment failed 3 days ago — in the grace period', 'action' => 'Help them update their card or pay another way'])
        ->and($quiet->at_risk_since)->not->toBeNull();

    // Things improve: the score and the trend follow; at-risk clears.
    DB::table('tenant_subscriptions')->where('tenant_id', $this->quiet->id)->update(['status' => 'active', 'past_due_since' => null]);
    DB::table('site_launches')->insert(['tenant_id' => $this->quiet->id, 'business_created_at' => now()->subDays(40), 'launched_at' => now(), 'minutes' => 10]);
    DB::table('business_errors_daily')->delete();
    SupportTicket::query()->update(['rating' => 'good']);
    app(HealthScores::class)->score($this->quiet->id);
    $quiet->refresh();
    expect($quiet->score)->toBe(70)->and($quiet->previous_score)->toBe(0)->and($quiet->grade)->toBe('watch')->and($quiet->at_risk_since)->toBeNull();
});

it('counts out-of-credits only when the business actually uses credits', function () {
    $signals = fn () => $this->ctx->run($this->glow, fn () => array_column(app(HealthSignals::class)->collect($this->glow->id), 'key'));
    expect($signals())->toBe([]);
    $this->ctx->run($this->glow, function () {
        $w = app(CreditWallet::class);
        $w->grant('purchase', 3000, 'buy:1', 'Purchase');
        $w->charge('sms', 'sms:1', 'SMS');
    });
    expect($signals())->toBe(['out_of_credits']);
});

it('one module failing never hides the others', function () {
    app(HealthSignals::class)->register('broken', fn () => throw new RuntimeException('boom'));
    DB::table('tenant_subscriptions')->where('tenant_id', $this->glow->id)->update(['status' => 'suspended']);
    expect(array_column(app(HealthSignals::class)->collect($this->glow->id), 'key'))->toBe(['suspended']);
});

it('shows WURK who needs help — most MRR at risk first — with filters and a CSV', function () {
    DB::table('tenant_subscriptions')->where('tenant_id', $this->quiet->id)->update(['status' => 'past_due', 'past_due_since' => now()]);
    app(HealthScores::class)->scoreAll();
    $page = $this->admin('/health');
    $finance = $this->staffWithRole('managed-services', 'ms@wurk.co.za');
    $this->actingAs($finance)->get($page)->assertForbidden();

    $this->actingAs($this->staff)->get($page)->assertInertia(fn (Assert $p) => $p->component('Success::Health')
        ->has('rows.data', 1)->where('rows.data.0.name', 'Quiet Cafe') // "needs attention" by default
        ->where('summary.healthy.count', 1)->where('summary.healthy.mrr_cents', 59900));
    $this->actingAs($this->staff)->get($page.'?grade=healthy')->assertInertia(fn (Assert $p) => $p->where('rows.data.0.name', 'Glow Salon'));
    $this->actingAs($this->staff)->get($page.'?sort=name')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2)->where('rows.data.0.name', 'Glow Salon'));
    $this->actingAs($this->staff)->get($page.'?sort=name&q=quiet')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1));
    $this->actingAs($this->staff)->get($page.'?sort=name&package=growth')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.name', 'Glow Salon'));

    $csv = $this->actingAs($this->staff)->get($page.'/export?sort=name')->assertOk()->streamedContent();
    expect($csv)->toContain('Business,Package,Score')->toContain('"Quiet Cafe",pro,')->toContain('Help them update their card or pay another way');
});

it('emails the weekly digest — newly at risk first — to the address WURK chooses', function () {
    DB::table('tenant_subscriptions')->where('tenant_id', $this->quiet->id)->update(['status' => 'suspended']);
    app(HealthScores::class)->scoreAll();
    $this->actingAs($this->staff)->put($this->admin('/health/settings'), ['digest_email' => 'success@wurk.co.za'])->assertSessionHas('status', 'Weekly digest email saved.');
    $this->artisan('success:weekly-digest')->assertSuccessful();
    Mail::assertSent(HealthDigestMail::class, fn (HealthDigestMail $m) => $m->hasTo('success@wurk.co.za')
        && $m->subjectLine === 'Business health: 1 at risk, 1 new this week'
        && in_array('NEWLY AT RISK THIS WEEK', $m->lines, true) && str_contains(implode("\n", $m->lines), 'Quiet Cafe — 0/100, R999/month: Suspended for non-payment → Call about paying to reopen the site'));
});

it('counts server errors for the business they happened in', function () {
    $this->ctx->run($this->glow, fn () => report(new RuntimeException('Something broke')));
    report(new RuntimeException('Outside any business'));
    expect(DB::table('business_errors_daily')->where('tenant_id', $this->glow->id)->value('count'))->toBe(1)
        ->and(DB::table('business_errors_daily')->count())->toBe(1);
});
