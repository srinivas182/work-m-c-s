<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\SiteAsset;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteAssets;

/*
 * Sprint 20.2: short videos for the video hero and the Video section.
 * Tests that re-encode need ffmpeg: they run wherever it is installed and in CI's "real tools" job
 * (FFMPEG_LIVE=1, --fail-on-skipped), and are skipped elsewhere — never faked.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function ffmpegAvailable(): bool
{
    $p = new Process([(string) config('wurk.ffmpeg.binary'), '-version']);
    $p->run();

    return $p->isSuccessful();
}

/** A real clip made by ffmpeg: a test pattern with a tone, at the given size and length. */
function makeClip(int $seconds, int $width = 640, int $height = 360, string $ext = 'mp4'): string
{
    $path = sys_get_temp_dir().'/wurk-clip-'.bin2hex(random_bytes(4)).'.'.$ext;
    (new Process([(string) config('wurk.ffmpeg.binary'), '-v', 'error', '-y',
        '-f', 'lavfi', '-i', "testsrc=size={$width}x{$height}:rate=15", '-f', 'lavfi', '-i', 'sine=frequency=440',
        '-t', (string) $seconds, '-metadata', 'location=+34.0522-118.2437/', '-shortest', $path]))->setTimeout(60)->mustRun();

    return $path;
}

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
    $this->ctx->run($this->glow, function () {
        app(Team::class)->add($this->owner->id, 'Owner', 'owner');
        app(Pages::class)->ensureSite();
    });
    $this->videos = $this->app('/glow-salon/website/videos');
});

/** A stored video row without running ffmpeg (for listing, usage and deleting). */
function fakeVideo(object $test): SiteAsset
{
    return $test->ctx->run($test->glow, function () {
        $assets = app(SiteAssets::class);
        $poster = $assets->store(UploadedFile::fake()->image('poster.jpg', 1280, 720), null);
        $mp4 = 'sites/'.app(TenantContext::class)->id().'/'.strtolower($poster->id).'.mp4';
        Storage::disk('public')->put($mp4, 'mp4 bytes');
        $poster->update(['kind' => 'video', 'video_path' => $mp4, 'duration_ms' => 12000, 'name' => 'salon.mp4']);

        return $poster->refresh();
    });
}

it('re-encodes an upload to a light MP4 with a poster, and lists it for the editor', function () {
    $clip = makeClip(3, 1920, 1080);
    $response = $this->actingAs($this->owner)->post($this->videos, ['video' => new UploadedFile($clip, 'salon.mp4', 'video/mp4', null, true)])
        ->assertCreated()
        ->assertJsonPath('seconds', 3)
        ->assertJsonPath('width', 1280); // never wider than 1280
    @unlink($clip);

    $asset = $this->ctx->run($this->glow, fn () => SiteAsset::query()->findOrFail($response->json('id')));
    expect($asset->kind)->toBe('video')
        ->and($asset->duration_ms)->toBeGreaterThan(2500)->toBeLessThan(3600)
        ->and(Storage::disk('public')->exists((string) $asset->video_path))->toBeTrue()
        ->and($response->json('src'))->toEndWith('.mp4')
        ->and($response->json('poster'))->toEndWith('.webp');

    // Clean H.264 with no location or other metadata from the phone.
    $probe = new Process([(string) config('wurk.ffmpeg.probe'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams',
        Storage::disk('public')->path((string) $asset->video_path)]);
    $info = json_decode($probe->mustRun()->getOutput(), true);
    expect(collect($info['streams'])->firstWhere('codec_type', 'video')['codec_name'])->toBe('h264')
        ->and($info['format']['tags']['location'] ?? null)->toBeNull();

    $this->actingAs($this->owner)->getJson($this->videos)->assertOk()
        ->assertJsonPath('videos.0.id', $asset->id)
        ->assertJsonPath('max_seconds', 30);
    // Not offered as a photo.
    $this->actingAs($this->owner)->getJson($this->app('/glow-salon/website/photos'))->assertOk()->assertJsonCount(0, 'photos');
})->group('video')->skip(fn () => getenv('FFMPEG_LIVE') !== '1' && ! ffmpegAvailable(), 'Needs ffmpeg (CI sets FFMPEG_LIVE=1).');

it('keeps only the first 30 seconds of a longer clip', function () {
    $clip = makeClip(33, 320, 180);
    $this->actingAs($this->owner)->post($this->videos, ['video' => new UploadedFile($clip, 'long.mp4', 'video/mp4', null, true)])
        ->assertCreated()->assertJsonPath('seconds', 30);
    @unlink($clip);
})->group('video')->skip(fn () => getenv('FFMPEG_LIVE') !== '1' && ! ffmpegAvailable(), 'Needs ffmpeg (CI sets FFMPEG_LIVE=1).');

it('refuses a file that only claims to be a video', function () {
    $fake = sys_get_temp_dir().'/wurk-not-a-video.mp4';
    file_put_contents($fake, "\x00\x00\x00\x18ftypmp42".str_repeat('x', 2000));
    $this->actingAs($this->owner)->post($this->videos, ['video' => new UploadedFile($fake, 'clip.mp4', 'video/mp4', null, true)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('video');
    @unlink($fake);
    expect($this->ctx->run($this->glow, fn () => SiteAsset::query()->count()))->toBe(0);
})->group('video')->skip(fn () => getenv('FFMPEG_LIVE') !== '1' && ! ffmpegAvailable(), 'Needs ffmpeg (CI sets FFMPEG_LIVE=1).');

it('refuses photos and other files on the video upload', function () {
    $this->actingAs($this->owner)->post($this->videos, ['video' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['video' => 'MP4, MOV or WebM']);
    $this->actingAs($this->owner)->post($this->videos, ['video' => UploadedFile::fake()->create('big.mp4', 21 * 1024, 'video/mp4')], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['video' => 'under 20 MB']);
});

it('needs a signed-in member to list or upload videos', function () {
    $this->getJson($this->videos)->assertUnauthorized();
    $this->postJson($this->videos, [])->assertUnauthorized();
});

it('lists videos apart from photos in Media, shows where each is used, and deletes the MP4 with it', function () {
    $video = fakeVideo($this);
    $this->ctx->run($this->glow, function () use ($video) {
        $pages = app(Pages::class);
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $value = app(SiteAssets::class)->video($video);
        $pages->saveDraft($home, ['schema' => 1, 'blocks' => [['id' => 'v', 'type' => 'Video', 'version' => 1, 'props' => ['video' => ['src' => $value['src'], 'poster' => $value['poster']]]]]], $home->draft_revision, null);
    });
    $media = $this->app('/glow-salon/website/media');
    $this->actingAs($this->owner)->get($media)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('photos', [])
        ->where('videos.0.id', $video->id)
        ->where('videos.0.seconds', 12)
        ->where('videos.0.usedIn', ['Home']));

    $this->actingAs($this->owner)->delete($media.'/'.$video->id)->assertSessionHasErrors(['photo' => 'This video is still used on: Home. Remove it there first.']);

    $this->ctx->run($this->glow, function () {
        $pages = app(Pages::class);
        $home = Page::query()->where('is_home', true)->firstOrFail();
        $pages->saveDraft($home, ['schema' => 1, 'blocks' => []], $home->draft_revision, null);
    });
    $this->actingAs($this->owner)->delete($media.'/'.$video->id)->assertSessionHas('status', 'Video deleted.');
    expect(Storage::disk('public')->exists((string) $video->video_path))->toBeFalse()
        ->and(collect($video->variants)->contains(fn ($p) => Storage::disk('public')->exists($p)))->toBeFalse();
});

it('finds a video by its MP4 address as well as its poster', function () {
    $id = '01jabcdefghjkmnpqrstvwxyz0';
    expect(SiteAssets::idsIn(['https://cdn.test/sites/01T/'.$id.'.mp4']))->toBe([$id])
        ->and(SiteAssets::idsIn(['https://cdn.test/sites/01T/'.$id.'-1280.webp']))->toBe([$id]);
});

it('says plainly when the server cannot process videos, without blaming the file', function () {
    config(['wurk.ffmpeg.probe' => '/nonexistent/ffprobe']);
    $clip = sys_get_temp_dir().'/wurk-clip-header.mp4';
    file_put_contents($clip, "\x00\x00\x00\x18ftypmp42".str_repeat('x', 2000)); // passes the type check
    $this->actingAs($this->owner)->post($this->videos, ['video' => new UploadedFile($clip, 'clip.mp4', 'video/mp4', null, true)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors(['video' => 'Videos can’t be added right now']);
    @unlink($clip);
});
