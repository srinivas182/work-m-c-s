<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\SiteTemplate;
use Wurk\Website\Models\TemplateAsset;
use Wurk\Website\Support\LaunchChecklist;
use Wurk\Website\Support\SiteBuilder;
use Wurk\Website\Support\TemplatePhotoImporter;
use Wurk\Website\Support\TemplatePhotos;
use Wurk\Website\Support\Templates;

/*
 * Sprint 20.3: demo photos. HQ fills a design's photo slots with licensed photos (link or upload); new sites get them,
 * marked demo, and the launch checklist counts the demo photos left to replace.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

/** A real JPEG, as a photo site would send it. */
function jpegBytes(int $w = 1800, int $h = 1200): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 120, 60, 40));
    ob_start();
    imagejpeg($img, null, 85);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake('public');
    $this->artisan('website:install-templates', ['--only' => ['health-dental', 'spa-day-spa']]);
    $this->staff = $this->staffWithRole('super-admin', 'design@wurk.co.za');
    $this->dental = SiteTemplate::query()->where('key', 'health-dental')->firstOrFail();
    $this->base = $this->admin('/templates/health-dental');
});

it('lists every photo place of a design, with what it should show and where to find one', function () {
    $this->actingAs($this->staff)->get($this->base)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Website::Admin/Template')
        ->where('demoPhotos.0.slot', 'hero')
        ->where('demoPhotos.0.shows', 'A dentist with a relaxed, smiling patient in a bright surgery')
        ->where('demoPhotos.0.photo', '/images/templates/health-dental/hero-640.webp')
        ->where('demoPhotos.0.chosen', null)
        ->where('demoPhotos.0.find.0', ['site' => 'Nappy', 'url' => 'https://nappy.co/search?q=Black%20dentist%20patient%20smile'])
        ->where('demoPhotos.1.slot', 'room')
        ->has('licences', 6));
});

it('every design’s photo places are described in the catalogue', function () {
    foreach (glob(base_path('modules/Website/templates/*.json')) ?: [] as $file) {
        $source = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach (TemplatePhotos::slots($source['key'], $source['definition']) as $slot) {
            expect($slot['shows'])->not->toStartWith('A photo for', "{$source['key']}/{$slot['slot']} has no description");
        }
    }
});

it('imports a photo from a Nappy page link, credits the photographer, and new sites get it marked demo', function () {
    Http::fake([
        'https://nappy.co/photo/12345' => Http::response('<html><head><meta property="og:title" content="Dentist and patient — Photo by Thandi M | Nappy"><meta property="og:image" content="https://images.nappy.co/photo/12345.jpg"></head></html>', 200, ['Content-Type' => 'text/html; charset=utf-8']),
        'https://images.nappy.co/photo/12345.jpg' => Http::response(jpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $this->actingAs($this->staff)->post($this->base.'/demo-photos/hero', ['link' => 'https://nappy.co/photo/12345', 'licence' => 'Nappy (CC0)'])
        ->assertSessionHasNoErrors()->assertSessionHas('status');

    $asset = TemplateAsset::query()->where('slot', 'hero')->firstOrFail();
    expect($asset->only(['photographer', 'licence', 'source_url', 'ai_generated']))
        ->toBe(['photographer' => 'Thandi M', 'licence' => 'Nappy (CC0)', 'source_url' => 'https://nappy.co/photo/12345', 'ai_generated' => false])
        ->and(array_keys($asset->variants))->toBe([640, 1600]);

    // Shown and applied with HQ's photo in place of the stand-in; the other slot keeps its stand-in.
    $definition = json_encode(app(Templates::class)->published('health-dental')['definition']);
    expect($definition)->not->toContain('/images\/templates\/health-dental\/hero-')
        ->and($definition)->toContain('templates\/health-dental\/'.strtolower($asset->id).'-1600.webp')
        ->and($definition)->toContain('/images\/templates\/health-dental\/room-1600.webp');

    $this->createShards(1);
    $tenant = $this->createTenant('bright-smiles');
    $ctx = app(TenantContext::class);
    $ctx->run($tenant, function () use ($asset) {
        app(SiteBuilder::class)->apply('health-dental');
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $demos = TemplatePhotos::demosIn((string) $home->draft);
        expect(collect($demos)->contains(fn ($d) => str_contains($d, strtolower($asset->id))))->toBeTrue();
        $photos = collect(app(LaunchChecklist::class)->steps())->firstWhere('key', 'photos');
        $total = Page::query()->get()->flatMap(fn (Page $p) => TemplatePhotos::demosIn((string) $p->draft))->unique()->count();
        expect($photos['label'])->toBe("Replace demo photos ({$total} left)")->and($photos['done'])->toBeFalse();
    });
});

it('refuses links to other sites, pages without a photo, and redirects or files that aren’t photos', function () {
    Http::fake([
        'https://www.pexels.com/photo/no-image/' => Http::response('<html><head><title>x</title></head></html>', 200, ['Content-Type' => 'text/html']),
        'https://images.unsplash.com/not-a-photo' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
        'https://images.pexels.com/missing.jpg' => Http::response('', 404),
    ]);
    $post = fn (string $link) => $this->actingAs($this->staff)->post($this->base.'/demo-photos/hero', ['link' => $link, 'licence' => 'Pexels licence']);
    $post('https://example.com/photo.jpg')->assertSessionHasErrors(['link' => 'Paste a link to a photo on Nappy, Pexels or Unsplash.']);
    $post('http://images.pexels.com/a.jpg')->assertSessionHasErrors('link'); // not https
    $post('https://user@images.pexels.com/a.jpg')->assertSessionHasErrors('link');
    $post('https://images.pexels.com:8443/a.jpg')->assertSessionHasErrors('link');
    $post('https://www.pexels.com/photo/no-image/')->assertSessionHasErrors(['link' => 'That page has no photo we can use. Open the photo itself and copy its link.']);
    $post('https://images.unsplash.com/not-a-photo')->assertSessionHasErrors(['link' => 'That link isn’t a JPEG, PNG or WebP photo.']);
    $post('https://images.pexels.com/missing.jpg')->assertSessionHasErrors(['link' => 'That link didn’t open (the site answered 404).']);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'example.com'));
    expect(TemplateAsset::query()->count())->toBe(0)
        ->and(TemplatePhotoImporter::allowed('https://images.nappy.co.evil.test/x.jpg'))->toBeFalse();
});

it('uploads a photo for a place, keeps AI images to decorative places, and can go back to the stand-in', function () {
    $this->actingAs($this->staff)->post($this->base.'/demo-photos/room', ['photo' => UploadedFile::fake()->image('room.jpg', 1600, 1000), 'licence' => 'Paid licence', 'photographer' => 'Studio K'])
        ->assertSessionHasNoErrors();
    expect(TemplateAsset::query()->where('slot', 'room')->value('photographer'))->toBe('Studio K');

    // health-dental has no decorative places; the day spa's stones-and-candles place is decorative.
    $this->actingAs($this->staff)->post($this->base.'/demo-photos/room', ['photo' => UploadedFile::fake()->image('ai.jpg'), 'licence' => 'WURK AI'])
        ->assertSessionHasErrors(['licence' => 'AI images are only for decorative places (backgrounds, textures). This place shows real people or work.']);
    $this->actingAs($this->staff)->post($this->admin('/templates/spa-day-spa/demo-photos/treat-3'), ['photo' => UploadedFile::fake()->image('ai.jpg', 1600, 1000), 'licence' => 'WURK AI'])
        ->assertSessionHasNoErrors();
    expect(TemplateAsset::query()->where('slot', 'treat-3')->value('ai_generated'))->toBeTrue();

    $this->actingAs($this->staff)->post($this->base.'/demo-photos/no-such-place', ['photo' => UploadedFile::fake()->image('x.jpg'), 'licence' => 'Paid licence'])->assertNotFound();

    $this->actingAs($this->staff)->delete($this->base.'/demo-photos/room')->assertSessionHas('status');
    expect(json_encode(app(Templates::class)->published('health-dental')['definition']))->toContain('/images\/templates\/health-dental\/room-1600.webp');
});

it('is only for staff who manage templates', function () {
    $support = $this->staffWithRole('support', 'support@wurk.co.za');
    $this->actingAs($support)->post($this->base.'/demo-photos/hero', ['photo' => UploadedFile::fake()->image('x.jpg'), 'licence' => 'Paid licence'])->assertForbidden();
    $this->actingAs($support)->delete($this->base.'/demo-photos/hero')->assertForbidden();
});

it('imports a list of links in one go, safe to run again, and reports problems without stopping', function () {
    Http::fake(['https://images.pexels.com/*' => Http::response(jpegBytes(), 200, ['Content-Type' => 'image/jpeg'])]);
    $list = sys_get_temp_dir().'/wurk-photo-list.json';
    file_put_contents($list, json_encode([
        ['template' => 'health-dental', 'slot' => 'hero', 'link' => 'https://images.pexels.com/1.jpg', 'photographer' => 'A. Dlamini', 'licence' => 'Pexels licence'],
        ['template' => 'health-dental', 'slot' => 'lobby', 'link' => 'https://images.pexels.com/2.jpg', 'licence' => 'Pexels licence'],
        ['template' => 'nope', 'slot' => 'hero', 'link' => 'https://images.pexels.com/3.jpg', 'licence' => 'Pexels licence'],
        ['template' => 'health-dental', 'slot' => 'room', 'link' => 'https://images.pexels.com/4.jpg', 'licence' => 'WURK AI'],
    ]));
    $this->artisan('website:import-template-photos', ['file' => $list])->assertFailed()
        ->expectsOutputToContain('#2 health-dental/lobby: the design has no such photo place')
        ->expectsOutputToContain('#3 nope/hero: no such template')
        ->expectsOutputToContain('Imported 1, skipped 0 already there; 3 problem(s).');
    $this->artisan('website:import-template-photos', ['file' => $list])->expectsOutputToContain('Imported 0, skipped 1 already there');
    expect(TemplateAsset::query()->where('slot', 'hero')->count())->toBe(1);
    @unlink($list);
});

it('WURK’s demo photo list covers every photo place with a licensed Pexels photo, none used twice', function () {
    $rows = json_decode((string) file_get_contents(base_path('modules/Website/templates/photos/photos.json')), true, flags: JSON_THROW_ON_ERROR);
    $listed = collect($rows)->map(fn (array $r) => $r['template'].'/'.$r['slot'])->all();
    $places = [];
    foreach (glob(base_path('modules/Website/templates/*.json')) ?: [] as $file) {
        $source = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        foreach (TemplatePhotos::slots($source['key'], $source['definition']) as $slot) {
            $places[] = $source['key'].'/'.$slot['slot'];
        }
    }
    expect($listed)->toEqualCanonicalizing($places)
        ->and(collect($rows)->pluck('link')->unique()->count())->toBe(count($rows));
    foreach ($rows as $r) {
        expect(TemplatePhotoImporter::allowed($r['link']))->toBeTrue($r['link'])
            ->and($r['source'])->toStartWith('https://www.pexels.com/photo/')
            ->and($r['licence'])->toBe('Pexels licence');
    }
});

it('installs the demo photos with the templates by default, skips them next time, and never fails the install for a photo', function () {
    config(['wurk.template_demo_photos' => true]);
    Http::fake(['https://images.pexels.com/*' => Http::sequence()
        ->push(jpegBytes(), 200, ['Content-Type' => 'image/jpeg'])
        ->push('gone', 404)
        ->whenEmpty(Http::response(jpegBytes(), 200, ['Content-Type' => 'image/jpeg']))]);
    $places = count(TemplatePhotos::slots('health-dental', (array) json_decode((string) $this->dental->draft, true)));

    $this->artisan('website:install-templates', ['--only' => ['health-dental']])->assertSuccessful()
        ->expectsOutputToContain('Some demo photos weren’t imported');
    expect(TemplateAsset::query()->count())->toBe($places - 1)
        ->and(TemplateAsset::query()->first()->licence)->toBe('Pexels licence')
        ->and(TemplateAsset::query()->first()->source_url)->toStartWith('https://www.pexels.com/photo/');

    // Next deploy: only the missing photo is fetched; the others are already there.
    $this->artisan('website:install-templates', ['--only' => ['health-dental']])->assertSuccessful()
        ->expectsOutputToContain('Imported 1, skipped '.($places - 1).' already there');
    expect(TemplateAsset::query()->count())->toBe($places);

    // --no-photos (and WURK_TEMPLATE_DEMO_PHOTOS=false) leave the photos alone.
    Http::fake(fn () => throw new RuntimeException('no fetch expected'));
    $this->artisan('website:install-templates', ['--only' => ['spa-day-spa'], '--no-photos' => true])->assertSuccessful();
    expect(TemplateAsset::query()->count())->toBe($places);
});

it('tells demo photos from the business’s own', function () {
    expect(TemplatePhotos::isDemo('/images/templates/health-dental/hero-1600.webp'))->toBeTrue()
        ->and(TemplatePhotos::isDemo('https://cdn.test/storage/templates/health-dental/01jabc-640.webp'))->toBeTrue()
        ->and(TemplatePhotos::isDemo('https://cdn.test/storage/sites/01T/01jabc-1600.webp'))->toBeFalse()
        ->and(TemplatePhotos::demosIn(['a' => '/images/templates/x/hero-1600.webp', 'b' => '/images/templates/x/hero-640.webp', 'c' => '/storage/sites/t/me-1600.webp']))
        ->toBe(['/images/templates/x/hero']);
});

it('offers owners the design’s demo set: HQ’s photo where there is one, the stand-in elsewhere', function () {
    $this->actingAs($this->staff)->post($this->base.'/demo-photos/room', ['photo' => UploadedFile::fake()->image('room.jpg', 1600, 1000), 'licence' => 'Paid licence'])->assertSessionHasNoErrors();
    $set = app(TemplatePhotos::class)->demoSet($this->dental);
    expect(array_column($set, 'name'))->toBe(['A dentist with a relaxed, smiling patient in a bright surgery', 'A clean, modern treatment room'])
        ->and($set[0]['src'])->toBe('/images/templates/health-dental/hero-1600.webp')
        ->and($set[1]['src'])->toContain('templates/health-dental/')->toEndWith('-1600.webp')
        ->and(TemplatePhotos::isDemo($set[1]['src']))->toBeTrue();
});
