<?php

declare(strict_types=1);

namespace Wurk\Website\Contracts;

use Illuminate\Http\UploadedFile;

/**
 * The business's photo library (Website's media), for other modules — e.g. product photos in the Shop.
 * One library: a photo uploaded anywhere appears everywhere.
 */
interface MediaLibrary
{
    /**
     * The photos (newest first) and storage use, as the photo picker expects.
     *
     * @return array{photos: list<array<string, mixed>>, used_mb: float, limit_mb: int}
     */
    public function library(): array;

    /**
     * Store an uploaded photo; returns it as the photo picker expects.
     *
     * @return array<string, mixed>
     */
    public function upload(UploadedFile $file, ?string $by, string $alt = ''): array;
}
