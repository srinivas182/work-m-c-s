<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedImageDriver;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\SiteAsset;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteAssets;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('public');
    SimulatedImageDriver::$failNext = 0;
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $package = app(PackageCatalog::class)->create([
        'key' => 'pro', 'name' => 'Pro', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 99900, 'yearly_price_cents' => 999000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'ai-assistant'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->glow, function () {
        app(Team::class)->add($this->owner->id, 'Owner', 'owner');
        app(Pages::class)->ensureSite();
        app(CreditWallet::class)->grant('purchase', 10000, 'buy:1', 'Purchase');
    });
    $this->url = $this->app('/glow-salon/website/ai/image');
});

it('generates a decorative image for 8 credits, stored like any photo and marked as AI', function () {
    $this->actingAs($this->owner)->postJson($this->url, ['prompt' => 'Warm light in a calm salon', 'confirmation' => 'c1'])
        ->assertCreated()->assertJson(['ai' => true]);

    $this->ctx->run($this->glow, function () {
        $asset = SiteAsset::query()->firstOrFail();
        expect($asset->ai_generated)->toBeTrue()
            ->and($asset->description)->toBe('Warm light in a calm salon')
            ->and(array_keys($asset->variants))->toContain(640)
            ->and(app(CreditWallet::class)->balance()['balance_milli'])->toBe(2000);
    });
});

it('refunds a failed image', function () {
    SimulatedImageDriver::$failNext = 1;
    $this->actingAs($this->owner)->postJson($this->url, ['prompt' => 'Warm light', 'confirmation' => 'c2'])->assertStatus(422);
    $this->ctx->run($this->glow, fn () => expect(app(CreditWallet::class)->balance()['balance_milli'])->toBe(10000));
});

it('allows AI images for decoration only, refusing them in sections that show real work', function () {
    $this->ctx->run($this->glow, function () {
        $ai = app(SiteAssets::class)->storeGenerated((string) app(SimulatedImageDriver::class)->generate('m', 'calm', 'k'), 'AI image', 'calm', null);
        $real = app(SiteAssets::class)->store(UploadedFile::fake()->image('braids.jpg', 1200, 900), null);
        $aiUrl = app(SiteAssets::class)->photo($ai)['src'];
        $realUrl = app(SiteAssets::class)->photo($real)['src'];
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $save = fn (array $blocks) => app(Pages::class)->saveDraft($home->refresh(), ['schema' => 1, 'blocks' => $blocks], $home->refresh()->draft_revision, null);

        $save([['id' => 'h', 'type' => 'Hero', 'version' => 2, 'props' => ['images' => [['src' => $aiUrl, 'alt' => 'Calm']]]]]); // decoration: fine
        $save([['id' => 'g', 'type' => 'Gallery', 'version' => 1, 'props' => ['images' => [['src' => $realUrl, 'alt' => 'Braids']]]]]); // real work: fine

        expect(fn () => $save([['id' => 'g', 'type' => 'Gallery', 'version' => 1, 'props' => ['images' => [['src' => $aiUrl, 'alt' => 'x']]]]]))
            ->toThrow(ValidationException::class, 'AI images can’t be used in a gallery of your work')
            ->and(fn () => $save([['id' => 'b', 'type' => 'BeforeAfter', 'version' => 1, 'props' => ['items' => [['before' => $realUrl, 'after' => $aiUrl]]]]]))
            ->toThrow(ValidationException::class, 'Before & after');
    });
});
