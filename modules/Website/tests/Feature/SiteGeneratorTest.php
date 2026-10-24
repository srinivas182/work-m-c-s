<?php

declare(strict_types=1);

use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Ai\SiteGenerator;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\Pages;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    SimulatedDriver::$failNext = 0;
    SimulatedDriver::$nextAnswer = null;
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $package = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 49900, 'yearly_price_cents' => 499000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'ai-assistant'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        $pages->ensureSite();
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $pages->saveDraft($home, ['schema' => 1, 'blocks' => [
            ['id' => 'hero', 'type' => 'Hero', 'version' => 2, 'props' => ['heading' => 'Old heading']],
            ['id' => 'says', 'type' => 'Testimonials', 'version' => 1, 'props' => ['heading' => 'Kind words', 'items' => []]],
            ['id' => 'prices', 'type' => 'ServicesPrices', 'version' => 1, 'props' => ['heading' => 'Prices', 'items' => []]],
        ]], $home->draft_revision, null);
        $pages->publish($home->fresh(), 'Live', null);
    });
    $this->brief = ['business' => 'Glow Salon', 'what' => 'Braids and natural hair care', 'area' => 'Durban North', 'services' => [['name' => 'Knotless braids', 'price' => 65000]], 'tone' => 'warm'];
});

it('writes only whitelisted text into drafts — never testimonials or invented prices', function () {
    SimulatedDriver::$nextAnswer = json_encode([
        'pages' => ['home' => [
            'hero' => ['heading' => str_repeat('Braids that last ', 10), 'text' => 'Natural hair, done with care.', 'price' => 99999],
            'says' => ['heading' => 'Rated 5 stars', 'items' => [['quote' => 'Best salon ever!', 'name' => 'A fake customer']]],
            'prices' => ['heading' => 'Our prices', 'items' => [['name' => 'Invented service', 'price' => 1]]],
        ]],
        'seo' => ['home' => ['title' => 'Glow Salon — braids in Durban North', 'description' => 'Braids and natural hair care.']],
    ]);

    $this->ctx->run($this->glow, function () {
        $live = PageVersion::query()->value('content');
        expect(app(SiteGenerator::class)->generate($this->brief, 'c1', null))->toBe(1);
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $blocks = collect(json_decode($home->draft, true)['blocks'])->keyBy('id');

        expect(mb_strlen($blocks['hero']['props']['heading']))->toBe(70)
            ->and($blocks['hero']['props'])->not->toHaveKey('price')
            ->and($blocks['says']['props'])->toBe(['heading' => 'Kind words', 'items' => []])   // testimonials untouched
            ->and($blocks['prices']['props']['heading'])->toBe('Our prices')
            ->and($blocks['prices']['props']['items'])->toBe([['name' => 'Knotless braids', 'description' => '', 'duration' => '', 'price' => 65000, 'from' => false]])
            ->and($home->seo_title)->toBe('Glow Salon — braids in Durban North')
            ->and(PageVersion::query()->value('content'))->toBe($live); // drafts only
    });
});

it('is free the first time, 20 credits after, and refunds an unusable answer', function () {
    $this->ctx->run($this->glow, function () {
        $wallet = app(CreditWallet::class);
        $wallet->grant('purchase', 25000, 'buy:1', 'Purchase');

        app(SiteGenerator::class)->generate($this->brief, 'c1', null); // simulated provider fills the slots
        expect($wallet->balance()['balance_milli'])->toBe(25000)
            ->and(json_decode(Page::query()->where('is_home', true)->value('draft'), true)['blocks'][0]['props']['heading'])->toStartWith('[Simulated] Glow Salon');

        SimulatedDriver::$nextAnswer = 'Sorry, I can’t help with that.';
        expect(fn () => app(SiteGenerator::class)->generate($this->brief, 'c2', null))->toThrow(AiUnavailable::class);
        expect($wallet->balance()['balance_milli'])->toBe(25000); // charged 20, refunded

        app(SiteGenerator::class)->generate($this->brief, 'c3', null);
        expect($wallet->balance()['balance_milli'])->toBe(5000);
    });
});

it('quotes and writes through the owner’s screen, showing refusals as a friendly message', function () {
    $owner = $this->person();
    $this->member($owner, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));
    $base = $this->app('/glow-salon/website/ai');

    $this->actingAs($owner)->get($base)->assertOk()->assertInertia(fn ($p) => $p->component('Website::AiWriter')->where('business', 'Glow-salon'));
    $this->actingAs($owner)->getJson("{$base}/quote?action=generate_site")->assertOk()->assertJson(['free' => true, 'credits' => 0, 'allowed' => true]);
    $this->actingAs($owner)->post("{$base}/generate", [...$this->brief, 'confirmation' => 'c1'])->assertRedirect()->assertSessionHas('status');

    // Second generation costs 20 credits; with none, a friendly refusal — not a server error.
    $this->actingAs($owner)->post("{$base}/generate", [...$this->brief, 'confirmation' => 'c2'])->assertSessionHasErrors(['ai' => 'You need 20 credits and have 0.']);
});
