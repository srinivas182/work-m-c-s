<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\Site;
use Wurk\Website\Models\SiteCategory;
use Wurk\Website\Models\SiteSnapshot;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;
use Wurk\Website\Support\Templates;
use Wurk\Website\Support\TemplateSwitcher;

uses(InteractsWithShards::class);

function design(string $layout, string $font, array $extraPages = []): array
{
    $home = ['schema' => 1, 'blocks' => [
        ['id' => 'hd', 'type' => 'Header', 'version' => 1, 'props' => []],
        ['id' => 'hero', 'type' => 'Hero', 'version' => 2, 'props' => ['layout' => $layout, 'heading' => 'Sample heading', 'images' => []]],
        ['id' => 'pr', 'type' => 'ServicesPrices', 'version' => 1, 'props' => ['heading' => 'Sample prices', 'items' => [['name' => 'Sample', 'price' => 10000]]]],
        ['id' => 'ft', 'type' => 'Footer', 'version' => 1, 'props' => []],
    ]];

    return [
        'pages' => [['key' => 'home', 'title' => 'Home', 'slug' => 'home', 'is_home' => true, 'content' => $home], ...$extraPages],
        'menus' => ['header' => [], 'footer' => []],
        'branding' => ['primary' => '#123456', 'font_pairing' => $font, 'corners' => 'square'],
    ];
}

function publishDesign(string $category, string $key, array $definition): void
{
    $templates = app(Templates::class);
    $t = $templates->create(SiteCategory::query()->where('key', $category)->firstOrFail(), $key, ucfirst($key), null, null, null);
    $templates->saveDraft($t, $definition, 1);
    $templates->publish($t->fresh(), null, null);
}

beforeEach(function () {
    $this->artisan('website:seed-categories');
    publishDesign('salon-beauty', 'salon-a', design('split', 'classic'));
    $about = ['key' => 'about', 'title' => 'About', 'slug' => 'about', 'is_home' => false, 'content' => ['schema' => 1, 'blocks' => [['id' => 'ab', 'type' => 'Text', 'version' => 1, 'props' => ['heading' => 'About us']]]]];
    publishDesign('salon-beauty', 'salon-b', design('photo', 'editorial', [$about]));
    publishDesign('health', 'dental', design('centered', 'modern'));

    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->glow, function () {
        app(SiteBuilder::class)->apply('salon-a');
        // The owner makes the site theirs.
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $doc = json_decode($home->draft, true);
        $doc['blocks'][1]['props'] = [...$doc['blocks'][1]['props'], 'heading' => 'Braids that last', 'images' => [['src' => '/storage/sites/t/me-1600.webp', 'alt' => 'Our salon']]];
        $doc['blocks'][2]['props']['items'] = [['name' => 'Knotless braids', 'price' => 65000], ['name' => 'Silk press', 'price' => 38000]];
        $doc['blocks'][] = ['id' => 'faq', 'type' => 'Faq', 'version' => 1, 'props' => ['items' => [['question' => 'Parking?', 'answer' => 'Yes, free']]]];
        app(Pages::class)->saveDraft($home, $doc, $home->draft_revision, null);
        app(Pages::class)->publish($home->fresh(), 'Live', null);
        app(Pages::class)->create('Our story', null, null);
        Site::query()->firstOrFail()->update(['menus' => ['header' => [['label' => 'Story', 'type' => 'page', 'page_id' => Page::query()->where('slug', 'our-story')->value('id')]], 'footer' => []],
            'branding' => [...Site::query()->firstOrFail()->branding, 'primary' => '#9D174D', 'logo' => '/storage/sites/t/logo-640.webp']]);
    });
});

it('switches design and keeps every text, photo, price, menu item and page', function () {
    $this->ctx->run($this->glow, function () {
        $liveBefore = PageVersion::query()->value('content');
        $site = app(TemplateSwitcher::class)->switch('salon-b', null);
        $home = json_decode(Page::query()->where('is_home', true)->value('draft'), true);
        $byType = collect($home['blocks'])->keyBy('type');

        expect($byType['Hero']['props'])->toMatchArray(['heading' => 'Braids that last', 'layout' => 'photo']) // content kept, style from the new design
            ->and($byType['Hero']['props']['images'])->toBe([['src' => '/storage/sites/t/me-1600.webp', 'alt' => 'Our salon']])
            ->and(array_column($byType['ServicesPrices']['props']['items'], 'name'))->toBe(['Knotless braids', 'Silk press'])
            ->and(array_column($home['blocks'], 'type'))->toBe(['Header', 'Hero', 'ServicesPrices', 'Faq', 'Footer']) // nothing dropped, above the footer
            ->and(Page::query()->orderBy('position')->pluck('slug')->all())->toContain('our-story', 'about')
            ->and($site->menus['header'][0]['label'])->toBe('Story')
            ->and($site->branding)->toMatchArray(['primary' => '#9D174D', 'logo' => '/storage/sites/t/logo-640.webp', 'font_pairing' => 'editorial', 'corners' => 'square'])
            ->and($site->template_key)->toBe('salon-b')
            ->and(SiteSnapshot::query()->count())->toBe(1)
            ->and(PageVersion::query()->value('content'))->toBe($liveBefore); // the live site waits for Publish
    });
});

it('only switches within the business’s category', function () {
    $this->ctx->run($this->glow, function () {
        expect(fn () => app(TemplateSwitcher::class)->switch('dental', null))->toThrow(ValidationException::class)
            ->and(app(TemplateSwitcher::class)->switch('salon-a', null)->template_key)->toBe('salon-a') // already on it: no change
            ->and(SiteSnapshot::query()->count())->toBe(0);
    });
});
