<?php

declare(strict_types=1);

namespace Wurk\Support\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Screenshots on tickets, kept private. Every image is decoded and re-encoded as PNG: anything hidden in the file
 * (scripts, other payloads, location and camera details) doesn't survive.
 */
final class Screenshots
{
    public const DISK = 'local';

    /** @return array{path: string, name: string, size: int} */
    public function store(string $ticketId, UploadedFile $file): array
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($image === false) {
            throw ValidationException::withMessages(['screenshots' => 'That file isn’t an image we can read. Use PNG, JPG or WebP.']);
        }
        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($image);
        $path = "support/{$ticketId}/".Str::ulid().'.png';
        Storage::disk(self::DISK)->put($path, $png);
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return ['path' => $path, 'name' => mb_substr(preg_replace('/[^\w .()-]+/u', '', $name) ?: 'screenshot', 0, 100).'.png', 'size' => strlen($png)];
    }
}
