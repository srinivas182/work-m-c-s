<?php

declare(strict_types=1);

use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Menus;
use Wurk\Website\Support\Pages;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
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
    [$this->home, $this->prices] = $this->ctx->run($this->glow, function () {
        app(Pages::class)->ensureSite();

        return [Page::query()->where('is_home', true)->firstOrFail(), app(Pages::class)->create('Prices', null, null)];
    });
    $this->url = $this->app('/glow-salon/website/menus');
});

it('saves menus that link to pages, web addresses and sections', function () {
    $this->actingAs($this->owner)->put($this->url, [
        'header' => [
            ['label' => 'Home', 'type' => 'page', 'page_id' => $this->home->id],
            ['label' => 'Services', 'type' => 'link', 'href' => '#services', 'children' => [
                ['label' => 'Prices', 'type' => 'page', 'page_id' => $this->prices->id],
            ]],
        ],
        'footer' => [['label' => 'Instagram', 'type' => 'link', 'href' => 'https://instagram.com/glowsalon'], ['label' => 'Call us', 'type' => 'link', 'href' => 'tel:+27 82 555 0101']],
    ])->assertSessionHasNoErrors()->assertSessionHas('status');

    $resolved = $this->ctx->run($this->glow, fn () => app(Menus::class)->resolve(Site::query()->firstOrFail()));
    expect($resolved['header'][0])->toBe(['label' => 'Home', 'href' => '/'])
        ->and($resolved['header'][1]['children'][0])->toBe(['label' => 'Prices', 'href' => '/prices'])
        ->and($resolved['footer'][1]['href'])->toBe('tel:+27 82 555 0101');
});

it('never accepts script, data or schemeless links', function () {
    foreach (['javascript:alert(1)', 'data:text/html,hi', 'JaVaScRiPt:alert(1)', 'www.example.com', 'http://insecure.example.com', ' https://ok.example.com x'] as $bad) {
        $this->actingAs($this->owner)->put($this->url, ['header' => [['label' => 'Bad', 'type' => 'link', 'href' => $bad]]])
            ->assertSessionHasErrors('header');
    }
    expect($this->ctx->run($this->glow, fn () => Site::query()->firstOrFail()->menus['header']))->toBe([]); // nothing unsafe was saved
});

it('keeps menus short and only one level deep, in the header only', function () {
    $item = fn (string $l) => ['label' => $l, 'type' => 'link', 'href' => '#'.strtolower($l)];
    $this->actingAs($this->owner)->put($this->url, ['header' => array_map($item, range('A', 'I'))])->assertSessionHasErrors('header'); // 9 > 8
    $this->actingAs($this->owner)->put($this->url, ['header' => [[...$item('A'), 'children' => [[...$item('B'), 'children' => [$item('C')]]]]]])->assertSessionHasErrors('header');
    $this->actingAs($this->owner)->put($this->url, ['footer' => [[...$item('A'), 'children' => [$item('B')]]]])->assertSessionHasErrors('footer');
    $this->actingAs($this->owner)->put($this->url, ['header' => [['label' => 'Ghost', 'type' => 'page', 'page_id' => '01NOTAPAGE']]])->assertSessionHasErrors('header');
});

it('follows a renamed page and drops a deleted one', function () {
    $this->actingAs($this->owner)->put($this->url, ['header' => [
        ['label' => 'Prices', 'type' => 'page', 'page_id' => $this->prices->id],
        ['label' => 'Home', 'type' => 'page', 'page_id' => $this->home->id],
    ]]);

    $this->ctx->run($this->glow, fn () => app(Pages::class)->update($this->prices->fresh(), ['title' => 'Prices', 'slug' => 'price-list']));
    expect($this->ctx->run($this->glow, fn () => app(Menus::class)->resolve(Site::query()->firstOrFail()))['header'][0]['href'])->toBe('/price-list');

    $this->ctx->run($this->glow, fn () => app(Pages::class)->delete($this->prices->fresh()));
    expect($this->ctx->run($this->glow, fn () => app(Menus::class)->resolve(Site::query()->firstOrFail()))['header'])->toBe([['label' => 'Home', 'href' => '/']]);
});
