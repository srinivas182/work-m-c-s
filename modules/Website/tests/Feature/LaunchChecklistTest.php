<?php

declare(strict_types=1);

use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\LaunchChecklist;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->artisan('website:install-templates');
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->glow, fn () => app(SiteBuilder::class)->apply('salon-hair-braids'));
    $this->done = fn () => $this->ctx->run($this->glow, fn () => collect(app(LaunchChecklist::class)->steps())->pluck('done', 'key')->all());
});

it('starts with every step open on a fresh template — sample photos included', function () {
    expect(($this->done)())->toBe(['logo' => false, 'contact' => false, 'prices' => false, 'photos' => false, 'hours' => false, 'legal' => false, 'publish' => false]);
});

it('ticks off each step from the site’s real state, or the owner’s confirmation', function () {
    $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        $site = Site::query()->firstOrFail();
        $site->update(['branding' => [...$site->branding, 'logo' => '/storage/sites/x/logo-640.webp']]);

        foreach (Page::query()->get() as $page) {
            $draft = str_replace(['\/images\/templates\/', '/images/templates/'], ['\/storage\/sites\/mine\/', '/storage/sites/mine/'], (string) $page->draft);
            $doc = json_decode($draft, true);
            foreach ($doc['blocks'] as &$b) {
                if ($b['type'] === 'Contact') {
                    $b['props']['phone'] = '082 555 0101';
                }
                if ($b['type'] === 'ServicesPrices' && $b['props']['items'] !== []) {
                    $b['props']['items'][0]['price'] = 65000;
                }
            }
            unset($b);
            $page->update(['draft' => json_encode($doc)]);
        }
        app(LaunchChecklist::class)->confirm('hours', true, null);
        app(LaunchChecklist::class)->confirm('legal', true, null);
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $pages->publish($home, 'Launch', null);
    });

    expect(($this->done)())->toBe(['logo' => true, 'contact' => true, 'prices' => true, 'photos' => true, 'hours' => true, 'legal' => true, 'publish' => true]);
});

it('lets the owner confirm only the steps that can’t be judged automatically', function () {
    $package = PackageCatalog::class;
    $p = app($package)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $owner = $this->person();
    $this->member($owner, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));
    $base = $this->app('/glow-salon/website/checklist');

    $this->actingAs($owner)->post("{$base}/prices")->assertRedirect();
    $this->actingAs($owner)->post("{$base}/publish")->assertNotFound();
    expect(($this->done)()['prices'])->toBeTrue();
});
