<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\SiteAsset;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function websitePhotoFixture(string $name): UploadedFile
{
    $path = __DIR__.'/../Fixtures/'.$name;
    $copy = tempnam(sys_get_temp_dir(), 'up');
    copy($path, $copy);

    return new UploadedFile($copy, $name, null, null, true);
}

beforeEach(function () {
    Storage::fake('public');
    [$one, $two] = $this->createShards(2);
    $this->glow = $this->createTenant('glow-salon', $one);
    $this->other = $this->createTenant('other-biz', $two);
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    foreach ([$this->glow, $this->other] as $t) {
        app(Catalog::class)->subscribe($t->id, $package->id, 'monthly', allowTrial: true);
    }
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
    $this->url = $this->app('/glow-salon/website/photos');
});

it('turns a phone photo the right way up and strips every trace of location and camera', function () {
    $response = $this->actingAs($this->owner)->post($this->url, ['photo' => websitePhotoFixture('phone-portrait-gps.jpg'), 'alt' => 'Our salon'])->assertCreated();

    // Stored 120×60 with "rotate 90°": the visitor must see a 60×120 portrait.
    $response->assertJson(['width' => 60, 'height' => 120, 'alt' => 'Our salon']);
    $asset = app(TenantContext::class)->run($this->glow, fn () => SiteAsset::query()->firstOrFail());
    expect($asset->only(['width', 'height']))->toBe(['width' => 60, 'height' => 120])
        ->and(array_keys($asset->variants))->toBe([60]); // smaller than every size: kept at its own width, never enlarged

    $stored = Storage::disk('public')->get($asset->variants[60]);
    [$w, $h, $type] = getimagesizefromstring($stored);
    expect([$w, $h, $type])->toBe([60, 120, IMAGETYPE_WEBP])
        ->and($stored)->not->toContain('SecretPhone')->not->toContain('GPS')->not->toContain('Exif');
    expect(Storage::disk('public')->allFiles())->toHaveCount(1); // the original upload is never kept
});

it('makes a phone-sized and a large WebP, and keeps transparency', function () {
    $response = $this->actingAs($this->owner)->post($this->url, ['photo' => websitePhotoFixture('logo-transparent.png')])->assertCreated();

    $asset = app(TenantContext::class)->run($this->glow, fn () => SiteAsset::query()->firstOrFail());
    expect(array_keys($asset->variants))->toBe([640, 1600]);
    $response->assertJson(['width' => 1600, 'height' => 800]);
    expect($response->json('srcset'))->toContain(' 640w')->toContain(' 1600w');

    $small = imagecreatefromstring(Storage::disk('public')->get($asset->variants[640]));
    expect(imagesx($small))->toBe(640)
        ->and((imagecolorat($small, 10, 10) >> 24) & 0x7F)->toBe(127); // still fully transparent
});

it('rejects files that are not photos, and oversized dimensions before decoding them', function () {
    $this->actingAs($this->owner)->postJson($this->url, ['photo' => websitePhotoFixture('not-a-photo.jpg')])->assertJsonValidationErrors(['photo' => 'Upload a JPEG, PNG or WebP photo.']);

    $before = memory_get_peak_usage();
    $this->actingAs($this->owner)->postJson($this->url, ['photo' => websitePhotoFixture('decompression-bomb.png')])->assertJsonValidationErrors('photo');
    expect(memory_get_peak_usage() - $before)->toBeLessThan(20 * 1024 * 1024) // 81 megapixels never decoded
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('enforces the package storage quota and keeps nothing that does not fit', function () {
    // Starter here has 1 MB; the business has already used all but a few kilobytes of it.
    app(TenantContext::class)->run($this->glow, fn () => SiteAsset::query()->create([
        'name' => 'earlier.webp', 'width' => 10, 'height' => 10, 'bytes' => 1024 * 1024 - 2000, 'variants' => [10 => 'sites/x/earlier.webp'],
    ]));

    $this->actingAs($this->owner)->postJson($this->url, ['photo' => websitePhotoFixture('logo-transparent.png')])
        ->assertJsonValidationErrors(['photo' => 'Your package includes 1 MB of storage and it’s full. Remove photos you don’t use, or upgrade for more space.']);
    expect(Storage::disk('public')->allFiles())->toBe([]) // the resized files were removed again
        ->and(app(TenantContext::class)->run($this->glow, fn () => SiteAsset::query()->count()))->toBe(1);
});

it('keeps each business’s photos to itself, under its own folder', function () {
    $this->actingAs($this->owner)->post($this->url, ['photo' => websitePhotoFixture('logo-transparent.png')])->assertCreated();

    $path = app(TenantContext::class)->run($this->glow, fn () => SiteAsset::query()->value('variants'))[640] ?? '';
    expect($path)->toStartWith('sites/'.$this->glow->id.'/');
    $this->actingAs($this->owner)->getJson($this->url)->assertJsonCount(1, 'photos');
    expect(app(TenantContext::class)->run($this->other, fn () => SiteAsset::query()->count()))->toBe(0);
});
