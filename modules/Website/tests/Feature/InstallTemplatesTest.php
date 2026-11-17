<?php

declare(strict_types=1);

use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\SiteTemplateVersion;
use Wurk\Website\Support\SiteBuilder;
use Wurk\Website\Support\TemplateSwitcher;

uses(InteractsWithShards::class);

it('installs WURK’s templates once, with legal pages, and republishes only when a file changes', function () {
    $this->artisan('website:install-templates')->assertSuccessful();
    $this->artisan('website:install-templates')->expectsOutputToContain('0 templates created, 0 updated');

    $hair = SiteTemplate::query()->where('key', 'salon-hair-braids')->firstOrFail();
    expect($hair->status)->toBe('published')
        ->and(SiteTemplateVersion::query()->where('template_id', $hair->id)->count())->toBe(1);
    $pages = array_column(json_decode(SiteTemplateVersion::query()->where('template_id', $hair->id)->value('definition'), true)['pages'], 'key');
    expect($pages)->toContain('home', 'services', 'contact', 'privacy', 'terms', 'cookies');

    $hair->update(['source_hash' => 'changed-file']); // as if the repository file had changed
    $this->artisan('website:install-templates')->expectsOutputToContain('0 templates created, 1 updated');
    expect(SiteTemplateVersion::query()->where('template_id', $hair->id)->count())->toBe(2);
});

it('builds a site with the business’s name in its legal pages, and switches design keeping the owner’s work', function () {
    $this->artisan('website:install-templates');
    $this->createShards(1);
    $glow = $this->createTenant('glow-salon', name: 'Glow Salon');

    app(TenantContext::class)->run($glow, function () {
        app(SiteBuilder::class)->apply('salon-hair-braids');
        $privacy = Page::query()->where('slug', 'privacy')->firstOrFail();
        expect($privacy->draft)->toContain('Glow Salon respects your privacy')
            ->and(Page::query()->pluck('draft')->implode(''))->not->toContain('{{')
            ->and($privacy->seo_title)->toBe('Privacy policy — Glow Salon');

        $home = Page::query()->where('is_home', true)->firstOrFail();
        $doc = json_decode($home->draft, true);
        $doc['blocks'][1]['props']['heading'] = 'Glow: braids in Durban North';
        $home->update(['draft' => json_encode($doc)]);

        app(TemplateSwitcher::class)->switch('salon-nails-lashes-barbers', null);
        $hero = collect(json_decode(Page::query()->where('is_home', true)->value('draft'), true)['blocks'])->firstWhere('type', 'Hero');
        expect($hero['props']['heading'])->toBe('Glow: braids in Durban North')   // the owner's words
            ->and($hero['props']['layout'])->toBe('collage')                         // the new design's look
            ->and(Page::query()->pluck('slug')->all())->toContain('gallery', 'privacy'); // nothing dropped
    });
});

it('previews a design in other colours — only real colours are taken (Sprint 18.5b)', function () {
    $this->artisan('website:install-templates');
    $url = 'http://'.config('wurk.domains.app').'/templates/salon-hair-braids/preview';
    $default = $this->get($url)->assertOk()->viewData('page')['props']['branding'];
    $this->get($url.'?primary=%23e53935&secondary=%23ffffff')->assertOk()
        ->assertInertia(fn ($p) => $p->where('branding.primary', '#E53935')->where('branding.secondary', '#FFFFFF'));
    $this->get($url.'?primary=red;background:url(x)')->assertOk()
        ->assertInertia(fn ($p) => $p->where('branding.primary', $default['primary']));
});
