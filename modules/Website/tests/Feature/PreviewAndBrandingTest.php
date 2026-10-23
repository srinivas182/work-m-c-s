<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Pages;

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
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
    $this->prices = $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        $page = $pages->create('Prices', null, null);
        $pages->saveDraft($page, ['schema' => 1, 'blocks' => [['id' => 't', 'type' => 'Text', 'version' => 1, 'props' => ['heading' => 'Published prices']]]], 1, null);
        $pages->publish($page, 'Live', null);
        $pages->saveDraft($page->fresh(), ['schema' => 1, 'blocks' => [['id' => 't', 'type' => 'Text', 'version' => 1, 'props' => ['heading' => 'Draft prices']]]], 2, null);
        Site::query()->firstOrFail()->update(['menus' => ['header' => [['label' => 'Prices', 'type' => 'page', 'page_id' => $page->id], ['label' => 'Insta', 'type' => 'link', 'href' => 'https://instagram.com/glow']], 'footer' => []]]);

        return $page;
    });
    $this->url = $this->app('/glow-salon/website/preview');
});

it('previews the latest changes or exactly the published version', function () {
    $this->actingAs($this->owner)->get($this->url.'?page=prices')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Website::Preview')->where('mode', 'draft')->where('content.blocks.0.props.heading', 'Draft prices'));
    $this->actingAs($this->owner)->get($this->url.'?page=prices&mode=published')
        ->assertInertia(fn (Assert $p) => $p->where('mode', 'published')->where('content.blocks.0.props.heading', 'Published prices'));
});

it('says when a page has not been published, and keeps menu links inside the preview', function () {
    $this->actingAs($this->owner)->get($this->url.'?mode=published') // home: never published
        ->assertInertia(fn (Assert $p) => $p->where('content', null)
            ->where('menus.header.0.href', '/glow-salon/website/preview?page=prices&mode=published')
            ->where('menus.header.1.href', 'https://instagram.com/glow'));
    $this->actingAs($this->owner)->get($this->url.'?page=no-such-page')->assertNotFound();
});

it('accepts only logos this business uploaded', function () {
    $url = $this->app('/glow-salon/website/branding');
    $own = Storage::disk('public')->url('sites/'.$this->glow->id.'/01abc-640.webp');
    $base = ['primary' => '#9D174D', 'font_pairing' => 'editorial', 'corners' => 'soft'];

    $this->actingAs($this->owner)->putJson($url, [...$base, 'logo' => 'https://evil.example.com/logo.png'])->assertJsonValidationErrors('logo');
    $this->actingAs($this->owner)->putJson($url, [...$base, 'logo' => Storage::disk('public')->url('sites/01OTHERBUSINESS/x-640.webp')])->assertJsonValidationErrors('logo');
    $this->actingAs($this->owner)->putJson($url, [...$base, 'logo' => $own])->assertOk()->assertJsonPath('branding.logo', $own)->assertJsonPath('branding.font_pairing', 'editorial');
});
