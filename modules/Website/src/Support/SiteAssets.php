<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\SiteAsset;

/**
 * Website photo uploads (Sprint 4.3). The upload is never stored: it is checked
 * by its real content, its dimensions are checked before decoding (no
 * "decompression bombs"), phone orientation is applied, and it is re-encoded
 * to WebP at a few widths. Re-encoding drops all metadata (GPS, device).
 */
final readonly class SiteAssets
{
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private TenantContext $context, private Entitlements $entitlements, private AuditLog $audit) {}

    public function store(UploadedFile $file, ?string $by, bool $aiGenerated = false, ?string $description = null): SiteAsset
    {
        $id = (string) Str::ulid();
        ['width' => $width, 'height' => $height, 'bytes' => $bytes, 'variants' => $variants] = $this->process($file, 'sites/'.$this->context->id(), $id);
        $disk = Storage::disk((string) config('wurk.site_assets_disk'));

        $this->assertQuota($bytes, $variants, $disk);

        $asset = SiteAsset::query()->create([
            'id' => $id, 'name' => mb_substr($file->getClientOriginalName(), 0, 160) ?: 'photo',
            'description' => $description === null ? null : mb_substr($description, 0, 200),
            'width' => $width, 'height' => $height,
            'bytes' => $bytes, 'variants' => $variants, 'ai_generated' => $aiGenerated, 'uploaded_by' => $by,
        ]);
        $this->audit->record($aiGenerated ? 'website.ai_image_created' : 'website.photo_uploaded', 'site_asset', $asset->id, ['name' => $asset->name, 'bytes' => $bytes]);

        return $asset;
    }

    /**
     * An image made by WURK AI: through the same safe pipeline as an upload (checked,
     * re-encoded to WebP sizes, quota enforced), marked AI-generated.
     */
    public function storeGenerated(string $bytes, string $name, string $description, ?string $by): SiteAsset
    {
        $path = tempnam(sys_get_temp_dir(), 'wurk-ai-');
        try {
            file_put_contents((string) $path, $bytes);
            $file = new UploadedFile((string) $path, $name.'.png', null, null, true);

            return $this->store($file, $by, aiGenerated: true, description: $description);
        } finally {
            @unlink((string) $path);
        }
    }

    /**
     * The safe upload pipeline, shared by business photos and template photos: checked by
     * content, dimensions checked before decoding, phone orientation applied, re-encoded
     * to WebP sizes (no metadata survives). Stores the files under $directory and returns
     * what was written; the original upload is never kept.
     *
     * @return array{width: int, height: int, bytes: int, variants: array<int, string>}
     */
    public function process(UploadedFile $file, string $directory, string $id): array
    {
        $config = (array) config('website.uploads');
        $fail = fn (string $message) => throw ValidationException::withMessages(['photo' => $message]);

        if ($file->getSize() > (int) $config['max_kb'] * 1024) {
            $fail('That photo is larger than '.((int) $config['max_kb'] / 1024).' MB.');
        }
        $info = @getimagesize($file->getRealPath());
        if ($info === false || ! in_array($info[2], self::TYPES, true)) {
            $fail('Upload a JPEG, PNG or WebP photo.');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if ($width < 1 || $height < 1 || max($width, $height) > (int) $config['max_side'] || $width * $height > (int) $config['max_pixels']) {
            $fail('That photo’s dimensions are too large. Use one under '.(int) $config['max_side'].' pixels on each side.');
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file->getRealPath()),
            IMAGETYPE_PNG => @imagecreatefrompng($file->getRealPath()),
            default => @imagecreatefromwebp($file->getRealPath()),
        };
        if (! $image instanceof GdImage) {
            $fail('That photo couldn’t be read. Try saving it again as a JPEG.');
        }
        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->orient($image, $file->getRealPath());
        }
        imagepalettetotruecolor($image);
        imagesavealpha($image, true);
        // Dimensions after orientation (a portrait phone photo becomes taller than wide).
        [$width, $height] = [imagesx($image), imagesy($image)];

        $disk = Storage::disk((string) config('wurk.site_assets_disk'));
        $variants = [];
        $bytes = 0;
        try {
            foreach ($this->widths($width, (array) $config['widths']) as $w) {
                $scaled = $w === $width ? $image : imagescale($image, $w, -1, IMG_BICUBIC);
                if (! $scaled instanceof GdImage) {
                    $fail('That photo couldn’t be resized.');
                }
                ob_start();
                imagewebp($scaled, null, (int) $config['webp_quality']);
                $data = (string) ob_get_clean();
                $path = $directory.'/'.strtolower($id).'-'.$w.'.webp';
                $disk->put($path, $data, ['visibility' => 'public']);
                $variants[$w] = $path;
                $bytes += strlen($data);
                if ($scaled !== $image) {
                    imagedestroy($scaled);
                }
            }
        } finally {
            imagedestroy($image);
        }

        return ['width' => $width, 'height' => $height, 'bytes' => $bytes, 'variants' => $variants];
    }

    /**
     * The value blocks store for a photo: largest variant as `src`, all as `srcset`.
     *
     * @return array{asset: string, src: string, srcset: string, width: int, height: int, alt: string}
     */
    public function photo(SiteAsset $asset, string $alt = ''): array
    {
        $disk = Storage::disk((string) config('wurk.site_assets_disk'));
        $widths = array_map('intval', array_keys($asset->variants));
        sort($widths);
        $largest = (int) end($widths);

        return [
            'asset' => $asset->id,
            'src' => $disk->url($asset->variants[$largest]),
            'srcset' => implode(', ', array_map(fn (int $w) => $disk->url($asset->variants[$w]).' '.$w.'w', $widths)),
            'width' => $largest,
            // Same aspect ratio as the photo, so the browser reserves the right space (no layout jump).
            'height' => (int) round($asset->height * $largest / max(1, $asset->width)),
            'alt' => $alt,
        ];
    }

    /**
     * Photo value from stored variant paths (template photos have no database row).
     *
     * @param  array<int, string>  $variants
     * @return array{src: string, srcset: string, width: int, height: int, alt: string}
     */
    public static function photoFromVariants(array $variants, int $width, int $height, string $alt = ''): array
    {
        $disk = Storage::disk((string) config('wurk.site_assets_disk'));
        $widths = array_map('intval', array_keys($variants));
        sort($widths);
        $largest = (int) end($widths);

        return [
            'src' => $disk->url($variants[$largest]),
            'srcset' => implode(', ', array_map(fn (int $w) => $disk->url($variants[$w]).' '.$w.'w', $widths)),
            'width' => $largest,
            'height' => (int) round($height * $largest / max(1, $width)),
            'alt' => $alt,
        ];
    }

    /**
     * A short video (Sprint 20.2): checked by its content (ffprobe), re-encoded to H.264 MP4 (at most 1280 wide and
     * 30 seconds; faststart for quick playback; metadata dropped), with a still poster through the photo pipeline.
     * The original upload is never kept or served.
     */
    public function storeVideo(UploadedFile $file, ?string $by): SiteAsset
    {
        $config = (array) config('website.videos');
        $fail = fn (string $message) => throw ValidationException::withMessages(['video' => $message]);
        if ($file->getSize() > (int) $config['max_mb'] * 1024 * 1024) {
            $fail('That video is larger than '.(int) $config['max_mb'].' MB. Use a shorter clip.');
        }
        $probe = new Process([(string) config('wurk.ffmpeg.probe'), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $file->getRealPath()]);
        $probe->setTimeout(30)->run();
        if ($probe->getExitCode() === 127) { // ffprobe isn't installed: the server's fault, not the file's
            report(new \RuntimeException('ffprobe is not installed; website videos cannot be processed (see docs/deployment/docker.md).'));
            $fail('Videos can’t be added right now. Please try again later, or use a photo.');
        }
        $info = json_decode($probe->getOutput(), true);
        $video = collect(is_array($info) ? (array) ($info['streams'] ?? []) : [])->firstWhere('codec_type', 'video');
        $formats = explode(',', (string) ($info['format']['format_name'] ?? ''));
        if (! $probe->isSuccessful() || ! is_array($video) || array_intersect($formats, ['mov', 'mp4', 'm4a', '3gp', 'matroska', 'webm']) === []) {
            $fail('Upload an MP4, MOV or WebM video.');
        }

        $id = (string) Str::ulid();
        $work = sys_get_temp_dir().'/wurk-video-'.$id;
        @mkdir($work, 0700, true);
        try {
            $max = (int) $config['max_width'];
            $encode = new Process([(string) config('wurk.ffmpeg.binary'), '-v', 'error', '-y', '-i', $file->getRealPath(), '-t', (string) (int) $config['max_seconds'],
                '-map', '0:v:0', '-map', '0:a:0?', '-vf', "scale='min({$max},iw)':-2", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-pix_fmt', 'yuv420p',
                '-c:a', 'aac', '-b:a', '96k', '-map_metadata', '-1', '-movflags', '+faststart', $work.'/out.mp4']);
            $encode->setTimeout(180)->run();
            $poster = new Process([(string) config('wurk.ffmpeg.binary'), '-v', 'error', '-y', '-ss', '1', '-i', $work.'/out.mp4', '-frames:v', '1', $work.'/poster.png']);
            if ($encode->isSuccessful()) {
                $poster->setTimeout(30)->run();
                if (! is_file($work.'/poster.png')) { // shorter than a second
                    $poster = new Process([(string) config('wurk.ffmpeg.binary'), '-v', 'error', '-y', '-i', $work.'/out.mp4', '-frames:v', '1', $work.'/poster.png']);
                    $poster->setTimeout(30)->run();
                }
            }
            if (! $encode->isSuccessful() || ! is_file($work.'/poster.png')) {
                $fail('That video couldn’t be read. Try saving it again as an MP4.');
            }
            $meta = json_decode((new Process([(string) config('wurk.ffmpeg.probe'), '-v', 'error', '-print_format', 'json', '-show_format', $work.'/out.mp4']))->mustRun()->getOutput(), true);
            $durationMs = (int) round((float) ($meta['format']['duration'] ?? 0) * 1000);

            $disk = Storage::disk((string) config('wurk.site_assets_disk'));
            $directory = 'sites/'.$this->context->id();
            $still = $this->process(new UploadedFile($work.'/poster.png', 'poster.png', 'image/png', null, true), $directory, $id);
            $mp4 = $directory.'/'.strtolower($id).'.mp4';
            $disk->put($mp4, (string) file_get_contents($work.'/out.mp4'), ['visibility' => 'public']);
            $bytes = $still['bytes'] + (int) filesize($work.'/out.mp4');
            $this->assertQuota($bytes, [...$still['variants'], $mp4], $disk);

            $asset = SiteAsset::query()->create([
                'id' => $id, 'kind' => 'video', 'name' => mb_substr($file->getClientOriginalName(), 0, 160) ?: 'video',
                'width' => $still['width'], 'height' => $still['height'], 'bytes' => $bytes, 'variants' => $still['variants'],
                'video_path' => $mp4, 'duration_ms' => $durationMs, 'ai_generated' => false, 'uploaded_by' => $by,
            ]);
            $this->audit->record('website.video_uploaded', 'site_asset', $asset->id, ['name' => $asset->name, 'bytes' => $bytes, 'seconds' => intdiv($durationMs, 1000)]);

            return $asset;
        } finally {
            foreach ((array) glob($work.'/*') as $f) {
                @unlink((string) $f);
            }
            @rmdir($work);
        }
    }

    /**
     * The value blocks store for a video: the MP4 and its poster.
     *
     * @return array{asset: string, src: string, poster: string, width: int, height: int, seconds: int}
     */
    public function video(SiteAsset $asset): array
    {
        $poster = $this->photo($asset);

        return ['asset' => $asset->id, 'src' => Storage::disk((string) config('wurk.site_assets_disk'))->url((string) $asset->video_path),
            'poster' => $poster['src'], 'width' => $poster['width'], 'height' => $poster['height'], 'seconds' => intdiv((int) $asset->duration_ms, 1000)];
    }

    public function usedBytes(): int
    {
        return (int) SiteAsset::query()->sum('bytes');
    }

    /**
     * The configured widths below the original, plus the original when it is no wider than the largest.
     *
     * @param  array<mixed>  $wanted
     * @return list<int>
     */
    private function widths(int $original, array $wanted): array
    {
        $sizes = array_values(array_map('intval', $wanted));
        $widths = array_values(array_filter($sizes, fn (int $w) => $w < $original));
        if ($sizes === [] || $original <= max($sizes)) {
            $widths[] = $original;
        }

        return array_values(array_unique($widths)) ?: [$original];
    }

    /** Phones store photos sideways with an orientation tag; GD ignores it, so apply it here. */
    private function orient(GdImage $image, string $path): GdImage
    {
        $orientation = (int) ((function_exists('exif_read_data') ? @exif_read_data($path) : [])['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
            $rotated = match ($orientation) {
                5 => imagerotate($rotated, 90, 0),
                7 => imagerotate($rotated, -90, 0),
                4 => imagerotate($rotated, 180, 0),
                default => $rotated,
            };
        }

        if ($rotated instanceof GdImage && $rotated !== $image) {
            imagedestroy($image); // free the unrotated copy

            return $rotated;
        }

        return $image;
    }

    /** @param  array<int, string>  $variants */
    private function assertQuota(int $bytes, array $variants, Filesystem $disk): void
    {
        $limitMb = (int) ($this->entitlements->limits($this->context->id())['storage_mb'] ?? 0);
        if ($limitMb > 0 && $this->usedBytes() + $bytes > $limitMb * 1024 * 1024) {
            $disk->delete(array_values($variants)); // nothing kept when it doesn't fit
            throw ValidationException::withMessages(['photo' => "Your package includes {$limitMb} MB of storage and it’s full. Remove photos you don’t use, or upgrade for more space."]);
        }
    }

    /**
     * Ids of this business's photos referenced anywhere in some content (decoded arrays
     * or stored JSON text — read as data, since stored JSON escapes "/").
     *
     * @return list<string> lower-case ids
     */
    public static function idsIn(mixed $data): array
    {
        $values = is_string($data) ? json_decode($data, true) : $data;
        if (! is_array($values)) {
            return [];
        }
        $ids = [];
        array_walk_recursive($values, function ($v) use (&$ids): void {
            if (is_string($v) && preg_match('#/sites/[0-9A-Za-z]+/([0-9a-z]{26})(?:-\d+\.webp|\.mp4)#', $v, $m) === 1) {
                $ids[$m[1]] = true;
            }
        });

        return array_keys($ids);
    }
}
