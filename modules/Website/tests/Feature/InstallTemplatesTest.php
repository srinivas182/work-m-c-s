<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\SiteTemplateVersion;
use Wurk\Website\Support\Branding;
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

it('previews a design in each of its three styles, which owners can choose and keep (Sprint 20.1)', function () {
    $this->artisan('website:install-templates');
    $url = 'http://'.config('wurk.domains.app').'/templates/salon-hair-braids/preview';
    $this->get($url.'?style=luxury')->assertOk()
        ->assertInertia(fn ($p) => $p->where('branding.style', 'luxury')->where('branding.font_pairing', 'elegant')->where('branding.corners', 'square'));
    $this->get($url.'?style=gaudy')->assertOk()->assertInertia(fn ($p) => $p->where('branding.style', 'minimal')); // only real styles

    $clean = app(Branding::class)->validate(['primary' => '#9D174D', 'font_pairing' => 'bold', 'corners' => 'round', 'style' => 'bold']);
    expect($clean['style'])->toBe('bold')
        ->and(app(Branding::class)->validate(['primary' => '#9D174D', 'font_pairing' => 'bold', 'corners' => 'round'])['style'])->toBe('minimal');
    expect(fn () => app(Branding::class)->validate(['primary' => '#9D174D', 'font_pairing' => 'bold', 'corners' => 'round', 'style' => 'gaudy']))->toThrow(ValidationException::class);

    // The renderer's presets (site/theme.ts) are the same as the server's.
    $ts = (string) file_get_contents(base_path('modules/Website/resources/js/site/theme.ts'));
    foreach (Branding::STYLE_PRESETS as $style => $preset) {
        expect($ts)->toMatch("/\\b{$style}: \\{[^}]*font_pairing: '{$preset['font_pairing']}',\\s*corners: '{$preset['corners']}'/s");
    }
});

it('ships complete designs: every photo exists in both sizes, no invented reviews, and enough designs per category', function () {
    $perCategory = [];
    foreach (glob(base_path('modules/Website/templates/*.json')) ?: [] as $file) {
        $source = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $perCategory[$source['category']] = ($perCategory[$source['category']] ?? 0) + 1;
        $json = (string) json_encode($source['definition'], JSON_UNESCAPED_SLASHES);
        preg_match_all('#/images/templates/([a-z0-9-]+)/([a-z0-9-]+)-1600\.webp#', $json, $m, PREG_SET_ORDER);
        expect($m)->not->toBeEmpty("{$source['key']} has no photos");
        foreach ($m as [$path, $key, $slot]) {
            expect($key)->toBe($source['key']);
            foreach ([640, 1600] as $w) {
                expect(is_file(public_path("images/templates/{$key}/{$slot}-{$w}.webp")))->toBeTrue("{$key}/{$slot}-{$w}.webp is missing");
            }
        }
        foreach ($source['definition']['pages'] as $page) {
            $ids = array_column($page['content']['blocks'], 'id');
            expect(array_unique($ids))->toHaveCount(count($ids), "{$source['key']}/{$page['key']} repeats a block id");
            foreach ($page['content']['blocks'] as $block) {
                if ($block['type'] === 'Testimonials') {
                    expect($block['props']['items'] ?? [])->toBe([], "{$source['key']} invents reviews"); // owners add real ones
                }
            }
        }
    }
    // Sprint 20.4 categories start with three designs each (20.5 brings the first twelve to three).
    foreach (['barbershop', 'cleaning', 'car-wash', 'catering', 'spaza-grocery', 'beauty-supplies', 'tailoring-fashion', 'agriculture', 'church-ngo', 'school', 'funeral-services', 'security', 'real-estate', 'legal-accounting'] as $category) {
        expect($perCategory[$category] ?? 0)->toBeGreaterThanOrEqual(3, "{$category} needs three designs");
    }
    expect(min($perCategory))->toBeGreaterThanOrEqual(2);
});

it('installs only the designs asked for, when given --only (tests use it to stay quick)', function () {
    $this->artisan('website:install-templates', ['--only' => ['health-dental', 'spa-day-spa']])->assertSuccessful()->expectsOutputToContain('2 templates created');
    expect(SiteTemplate::query()->pluck('key')->sort()->values()->all())->toBe(['health-dental', 'spa-day-spa']);
});
