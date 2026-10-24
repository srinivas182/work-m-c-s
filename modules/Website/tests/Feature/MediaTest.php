<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Models\SiteAsset;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteAssets;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('public');
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx = app(TenantContext::class);
    [$this->used, $this->logo, $this->spare] = $this->ctx->run($this->glow, function () {
        app(Team::class)->add($this->owner->id, 'Owner', 'owner');
        $pages = app(Pages::class);
        $pages->ensureSite();
        $assets = app(SiteAssets::class);
        [$used, $logo, $spare] = array_map(fn ($n) => $assets->store(UploadedFile::fake()->image("{$n}.jpg", 1200, 900), null), ['braids', 'logo', 'spare']);
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $pages->saveDraft($home, ['schema' => 1, 'blocks' => [['id' => 'g', 'type' => 'Gallery', 'version' => 1, 'props' => ['images' => [['src' => $assets->photo($used)['src'], 'alt' => 'Braids']]]]]], $home->draft_revision, null);
        $site = Site::query()->firstOrFail();
        $site->update(['branding' => [...$site->branding, 'logo' => $assets->photo($logo)['src']]]);

        return [$used, $logo, $spare];
    });
    $this->base = $this->app('/glow-salon/website/media');
});

it('shows every photo and where it is used', function () {
    $this->actingAs($this->owner)->get($this->base)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Website::Media')
        ->where('photos', fn ($photos) => collect($photos)->firstWhere('id', $this->used->id)['usedIn'] === ['Home']
            && collect($photos)->firstWhere('id', $this->logo->id)['usedIn'] === ['Logo']
            && collect($photos)->firstWhere('id', $this->spare->id)['usedIn'] === []));
});

it('refuses to delete a photo in use, and removes an unused one with its files', function () {
    $this->actingAs($this->owner)->delete("{$this->base}/{$this->used->id}")->assertSessionHasErrors(['photo' => 'This photo is still used on: Home. Remove it there first.']);
    $this->actingAs($this->owner)->delete("{$this->base}/{$this->logo->id}")->assertSessionHasErrors('photo');

    $files = array_values($this->spare->variants);
    $this->actingAs($this->owner)->delete("{$this->base}/{$this->spare->id}")->assertSessionHas('status', 'Photo deleted.');
    $this->ctx->run($this->glow, fn () => expect(SiteAsset::query()->pluck('id')->all())->not->toContain($this->spare->id));
    foreach ($files as $path) {
        Storage::disk('public')->assertMissing($path);
    }
    foreach ($this->used->variants as $path) {
        Storage::disk('public')->assertExists($path); // the photo in use keeps its files
    }
});

it('saves a description that the photo picker then offers as the alt text', function () {
    $this->actingAs($this->owner)->put("{$this->base}/{$this->spare->id}", ['description' => 'Our reception desk'])->assertSessionHas('status');
    $this->actingAs($this->owner)->getJson($this->app('/glow-salon/website/photos'))
        ->assertJson(fn ($json) => $json->where('photos', fn ($photos) => collect($photos)->firstWhere('id', $this->spare->id)['alt'] === 'Our reception desk')->etc());
});
