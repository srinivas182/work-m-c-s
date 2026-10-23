<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function store(UploadedFile $file, ?string $by): SiteAsset
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
        $id = (string) Str::ulid();
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
                $path = 'sites/'.$this->context->id().'/'.strtolower($id).'-'.$w.'.webp';
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

        $this->assertQuota($bytes, $variants, $disk);

        $asset = SiteAsset::query()->create([
            'id' => $id, 'name' => mb_substr($file->getClientOriginalName(), 0, 160) ?: 'photo',
            'width' => $width, 'height' => $height,
            'bytes' => $bytes, 'variants' => $variants, 'uploaded_by' => $by,
        ]);
        $this->audit->record('website.photo_uploaded', 'site_asset', $asset->id, ['name' => $asset->name, 'bytes' => $bytes]);

        return $asset;
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
}
