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

it('takes enquiries posted from the business’s published site, on its own address', function () {
    $site = 'http://glow-salon.'.config('wurk.domains.root');
    $this->post($site.'/forms/quote', $this->valid)->assertOk()->assertSee('your request was sent')->assertSee('Back to the website');
    $this->post($site.'/forms/quote', [...$this->valid, 'website' => 'http://spam.example'])->assertOk(); // spam trap: thanked, not stored
    expect($this->ctx->run($this->glow, fn () => Enquiry::query()->count()))->toBe(1)
        ->and($this->ctx->run($this->other, fn () => Enquiry::query()->count()))->toBe(0);
});

it('makes each reply a contact, opens a lead when the form asks, and records marketing consent only when ticked', function () {
    $this->post($this->url, [...$this->valid, 'lead' => '1'])->assertOk();                                  // quote form: opens a lead; news not ticked
    $this->post($this->url, [...$this->valid, 'phone' => '+27 82 555 0101', 'lead' => '0', 'news' => '1'])->assertOk(); // same person; no lead; ticked news
    $this->ctx->run($this->glow, function () {
        $db = DB::connection(app(TenantContext::class)->connectionName());
        $enquiries = Enquiry::query()->orderBy('created_at')->orderBy('id')->get();
        expect($db->table('contacts')->count())->toBe(1)                                                     // one person, two replies
            ->and($enquiries->pluck('contact_id')->unique()->count())->toBe(1)
            ->and($enquiries->whereNotNull('lead_id')->count())->toBe(1)
            ->and($enquiries->whereNotNull('marketing_consent_at')->count())->toBe(1)
            ->and($db->table('contacts')->value('marketing_consent_source'))->toBe('Website form');
        $lead = $db->table('leads')->first();
        expect($lead->title)->toBe('Quote request: Solar install')->and($lead->source)->toBe('quote')
            ->and($db->table('lead_events')->where('lead_id', $lead->id)->value('detail'))->toBe('Quote for a 5 kW system please.');
    });
    expect($this->ctx->run($this->other, fn () => DB::connection(app(TenantContext::class)->connectionName())->table('contacts')->count()))->toBe(0);
});

it('takes a general form’s own questions: a waiting list without a message, answers stored and in the owner’s email and the lead', function () {
    $this->ctx->run($this->glow, fn () => Site::query()->firstOrFail()->update(['settings' => ['enquiry_email' => 'owner@glow.co.za']]));
    $this->post($this->url, ['name' => 'Lerato', 'email' => 'lerato@example.com', 'consent' => '1', 'form' => 'waiting_list', 'lead' => '1',
        'answers' => [['q' => 'Which class?', 'a' => 'Saturday yoga'], ['q' => 'Anything else?', 'a' => '']]])->assertOk();
    $this->postJson($this->url, ['name' => 'Bob', 'email' => 'bob@example.com', 'consent' => '1'])->assertStatus(422)->assertJsonValidationErrors('message'); // quote forms still need a message (no session on this public route)

    Mail::assertSent(EnquiryMail::class, fn ($m) => str_contains($m->render(), 'Someone joined your waiting list') && str_contains($m->render(), 'Which class?: Saturday yoga') && ! str_contains($m->render(), 'Anything else?'));
    $this->ctx->run($this->glow, function () {
        $e = Enquiry::query()->sole();
        $db = DB::connection(app(TenantContext::class)->connectionName());
        expect($e->form)->toBe('waiting_list')->and($e->answers)->toBe([['q' => 'Which class?', 'a' => 'Saturday yoga']])
            ->and($db->table('leads')->value('title'))->toBe('Waiting list: Lerato')
            ->and($db->table('lead_events')->value('detail'))->toBe('Which class?: Saturday yoga');
    });
});

it('shows the owner each enquiry’s kind, answers, and links to its contact and lead', function () {
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
    $this->post($this->url, [...$this->valid, 'lead' => '1', 'answers' => [['q' => 'Roof type?', 'a' => 'Tiles']]])->assertOk();
    [$contact, $lead] = $this->ctx->run($this->glow, fn () => [Enquiry::query()->value('contact_id'), Enquiry::query()->value('lead_id')]);

    $this->actingAs($owner)->get($this->app('/glow-salon/website/enquiries'))->assertInertia(fn (Assert $p) => $p->component('Website::Enquiries')
        ->where('enquiries.0.form', 'quote')->where('enquiries.0.answers.0', ['q' => 'Roof type?', 'a' => 'Tiles'])
        ->where('enquiries.0.contact_id', $contact)->where('enquiries.0.lead_id', $lead));
    expect($contact)->not->toBeNull()->and($lead)->not->toBeNull();
});
