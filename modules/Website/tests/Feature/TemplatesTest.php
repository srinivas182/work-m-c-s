<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Exceptions\DraftConflict;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\Site;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Models\SiteTemplateVersion;
use Wurk\Website\Support\Menus;
use Wurk\Website\Support\SiteBuilder;
use Wurk\Website\Support\TemplateDefinition;
use Wurk\Website\Support\Templates;

uses(InteractsWithShards::class);

function salonDefinition(): array
{
    $text = fn (string $id, string $heading) => ['schema' => 1, 'blocks' => [['id' => $id, 'type' => 'Text', 'version' => 1, 'props' => ['heading' => $heading]]]];

    return [
        'pages' => [
            ['key' => 'home', 'title' => 'Home', 'slug' => 'home', 'is_home' => true, 'content' => $text('h', 'Braids that last')],
            ['key' => 'prices', 'title' => 'Prices', 'slug' => 'prices', 'is_home' => false, 'content' => $text('p', 'Our prices')],
        ],
        'menus' => ['header' => [['label' => 'Prices', 'type' => 'page', 'page' => 'prices'], ['label' => 'Instagram', 'type' => 'link', 'href' => 'https://instagram.com/x']], 'footer' => []],
        'branding' => ['primary' => '#9d174d', 'secondary' => null, 'font_pairing' => 'classic', 'corners' => 'soft'],
    ];
}

beforeEach(function () {
    $this->artisan('website:seed-categories')->assertSuccessful();
    $this->salon = SiteCategory::query()->where('key', 'salon-beauty')->firstOrFail();
    $this->templates = app(Templates::class);
});

it('seeds the 12 agreed categories once, without overwriting edits', function () {
    $this->salon->update(['name' => 'Salons & beauty']);
    $this->artisan('website:seed-categories')->expectsOutputToContain('0 categories created');

    expect(SiteCategory::query()->count())->toBe(12)
        ->and($this->salon->fresh()->name)->toBe('Salons & beauty');
});

it('rejects template definitions that would produce a broken site', function () {
    $good = salonDefinition();
    expect(TemplateDefinition::validate($good)['branding']['primary'])->toBe('#9D174D');

    $cases = [
        'no pages' => [...$good, 'pages' => []],
        'two homes' => [...$good, 'pages' => [$good['pages'][0], [...$good['pages'][1], 'is_home' => true]]],
        'duplicate key' => [...$good, 'pages' => [$good['pages'][0], [...$good['pages'][1], 'key' => 'home']]],
        'reserved address' => [...$good, 'pages' => [$good['pages'][0], [...$good['pages'][1], 'slug' => 'sitemap']]],
        'bad content' => [...$good, 'pages' => [[...$good['pages'][0], 'content' => ['schema' => 9, 'blocks' => []]]]],
        'menu to a missing page' => [...$good, 'menus' => ['header' => [['label' => 'Ghost', 'type' => 'page', 'page' => 'nope']], 'footer' => []]],
        'unsafe link' => [...$good, 'menus' => ['header' => [['label' => 'Bad', 'type' => 'link', 'href' => 'javascript:alert(1)']], 'footer' => []]],
        'nested footer' => [...$good, 'menus' => ['header' => [], 'footer' => [['label' => 'A', 'type' => 'link', 'href' => '#a', 'children' => [['label' => 'B', 'type' => 'link', 'href' => '#b']]]]]],
        'unknown font' => [...$good, 'branding' => [...$good['branding'], 'font_pairing' => 'comic']],
    ];
    foreach ($cases as $name => $definition) {
        expect(fn () => TemplateDefinition::validate($definition))->toThrow(ValidationException::class, null, $name);
    }
});

it('publishes immutable versions; later draft edits never reach businesses until published', function () {
    $template = $this->templates->create($this->salon, 'salon-hair', 'Hair & braids salon', null, null, null);
    $this->templates->saveDraft($template, salonDefinition(), 1);
    $v1 = $this->templates->publish($template->fresh(), 'First version', null);

    $changed = salonDefinition();
    $changed['pages'][0]['title'] = 'Welcome';
    $this->templates->saveDraft($template->fresh(), $changed, 2);

    expect($this->templates->published('salon-hair')['definition']['pages'][0]['title'])->toBe('Home')
        ->and(fn () => $this->templates->saveDraft($template->fresh(), $changed, 2))->toThrow(DraftConflict::class)
        ->and(fn () => $v1->update(['note' => 'x']))->toThrow(LogicException::class);

    $this->templates->publish($template->fresh(), null, null);
    expect($this->templates->published('salon-hair')['version'])->toBe(2)
        ->and(SiteTemplateVersion::query()->count())->toBe(2);
});

it('shows only categories with a published template, and hides retired ones', function () {
    expect($this->templates->catalogue())->toBe([]);

    $template = $this->templates->create($this->salon, 'salon-hair', 'Hair & braids salon', 'Bold and warm', null, null);
    $this->templates->saveDraft($template, salonDefinition(), 1);
    $this->templates->publish($template->fresh(), null, null);
    expect(array_column($this->templates->catalogue(), 'key'))->toBe(['salon-beauty'])
        ->and($this->templates->catalogue()[0]['templates'][0]['key'])->toBe('salon-hair');

    $this->templates->retire($template->fresh(), null);
    expect($this->templates->catalogue())->toBe([])
        ->and($this->templates->published('salon-hair'))->toBeNull();
});

it('builds a business’s site from a template: pages, menus linked to real pages, branding', function () {
    $template = $this->templates->create($this->salon, 'salon-hair', 'Hair & braids salon', null, null, null);
    $this->templates->saveDraft($template, salonDefinition(), 1);
    $this->templates->publish($template->fresh(), null, null);

    $this->createShards(1);
    $glow = $this->createTenant('glow-salon');
    app(TenantContext::class)->run($glow, function () {
        $site = app(SiteBuilder::class)->apply('salon-hair');
        $prices = Page::query()->where('slug', 'prices')->firstOrFail();

        expect($site->only(['category_key', 'template_key', 'template_version']))->toBe(['category_key' => 'salon-beauty', 'template_key' => 'salon-hair', 'template_version' => 1])
            ->and($site->branding)->toMatchArray(['primary' => '#9D174D', 'font_pairing' => 'classic', 'logo' => null])
            ->and(Page::query()->orderBy('position')->pluck('slug')->all())->toBe(['home', 'prices'])
            ->and(Page::query()->where('is_home', true)->value('slug'))->toBe('home')
            ->and(app(Menus::class)->resolve(Site::query()->firstOrFail())['header'])->toBe([['label' => 'Prices', 'href' => '/prices'], ['label' => 'Instagram', 'href' => 'https://instagram.com/x']])
            ->and($site->menus['header'][0]['page_id'])->toBe($prices->id)
            ->and(fn () => app(SiteBuilder::class)->apply('salon-hair'))->toThrow(ValidationException::class);
    });
});

it('publishes sample designs for development, and never in production', function () {
    $this->artisan('website:seed-sample-templates')->assertSuccessful();
    $this->artisan('website:seed-sample-templates')->assertSuccessful(); // safe to re-run
    expect(array_column(app(Templates::class)->catalogue(), 'key'))->toBe(['salon-beauty', 'health'])
        ->and(count(app(Templates::class)->catalogue()[0]['templates']))->toBe(2);

    app()->detectEnvironment(fn () => 'production');
    $this->artisan('website:seed-sample-templates')->assertFailed();
});
