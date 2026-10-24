<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Http\Controllers\QuoteFormController;
use Wurk\Website\Mail\EnquiryMail;
use Wurk\Website\Models\Enquiry;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Pages;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    [$one, $two] = $this->createShards(2);
    $this->glow = $this->createTenant('glow-salon', $one, name: 'Glow Salon');
    $this->other = $this->createTenant('other-biz', $two);
    $this->ctx = app(TenantContext::class);
    foreach ([$this->glow, $this->other] as $t) {
        $this->ctx->run($t, fn () => app(Pages::class)->ensureSite());
    }
    $this->url = $this->app('/forms/glow-salon/quote');
    $this->valid = ['name' => 'Sipho Ndlovu', 'phone' => '082 555 0101', 'service' => 'Solar install', 'message' => 'Quote for a 5 kW system please.', 'consent' => '1'];
});

it('stores a genuine request and emails the owner only if they asked for it', function () {
    $this->post($this->url, $this->valid)->assertOk()->assertSee('your request was sent');
    Mail::assertNothingSent();

    $this->ctx->run($this->glow, fn () => Site::query()->firstOrFail()->update(['settings' => ['enquiry_email' => 'owner@glow.co.za']]));
    $this->post($this->url, [...$this->valid, 'name' => 'Lerato'])->assertOk();
    Mail::assertSent(EnquiryMail::class, fn ($m) => $m->hasTo('owner@glow.co.za') && $m->enquiry['name'] === 'Lerato');

    expect($this->ctx->run($this->glow, fn () => Enquiry::query()->count()))->toBe(2)
        ->and($this->ctx->run($this->other, fn () => Enquiry::query()->count()))->toBe(0);
});

it('quietly discards bots and requires consent and a way to reply', function () {
    $this->post($this->url, [...$this->valid, 'website' => 'http://spam.example'])->assertOk()->assertSee('your request was sent');
    expect($this->ctx->run($this->glow, fn () => Enquiry::query()->count()))->toBe(0);

    $this->postJson($this->url, [...$this->valid, 'consent' => null])->assertJsonValidationErrors(['consent' => 'Please agree to be contacted about your request.']);
    $this->postJson($this->url, [...$this->valid, 'phone' => null])->assertJsonValidationErrors('phone');
    $this->postJson($this->url, [...$this->valid, 'phone' => null, 'email' => 'sipho@example.com'])->assertOk();
    $this->postJson($this->app('/forms/no-such-business/quote'), $this->valid)->assertNotFound();
});

it('never stores the visitor’s IP address, only a hash', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '41.13.250.7'])->post($this->url, $this->valid)->assertOk();
    $row = $this->ctx->run($this->glow, fn () => (array) DB::connection(app(TenantContext::class)->connectionName())->table('enquiries')->first());
    expect(json_encode($row))->not->toContain('41.13.250.7')->and(strlen($row['ip_hash']))->toBe(64);
});

it('caps requests per business per day', function () {
    $key = 'quote-cap:'.$this->glow->id.':'.now()->format('Y-m-d');
    foreach (range(1, QuoteFormController::DAILY_CAP) as $_) {
        RateLimiter::hit($key, 86400);
    }
    $this->post($this->url, $this->valid)->assertStatus(429);
});

it('lets the owner read, archive and choose where requests are emailed', function () {
    $owner = $this->person();
    $this->member($owner, $this->glow);
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: true);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));
    $this->post($this->url, $this->valid);
    $id = $this->ctx->run($this->glow, fn () => Enquiry::query()->value('id'));

    $page = $this->app('/glow-salon/website/enquiries');
    $this->actingAs($owner)->get($page)->assertInertia(fn (Assert $p) => $p->component('Website::Enquiries')->where('newCount', 1)->where('enquiries.0.name', 'Sipho Ndlovu'));
    $this->actingAs($owner)->put("{$page}/{$id}", ['status' => 'archived']);
    $this->actingAs($owner)->get($page)->assertInertia(fn (Assert $p) => $p->where('enquiries', []));
    $this->actingAs($owner)->put("{$page}/settings", ['notify_email' => 'not-an-email'])->assertSessionHasErrors('notify_email');
    $this->actingAs($owner)->put("{$page}/settings", ['notify_email' => 'owner@glow.co.za'])->assertSessionHas('status');
});
