<?php

declare(strict_types=1);

namespace Wurk\Website\Support\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Jobs\ScanFormFile;
use Wurk\Website\Models\EnquiryFile;

/**
 * Files visitors attach to forms. The type is read from the contents (never the name); images are re-saved
 * (dropping hidden content and location data); risky PDFs are refused; everything is encrypted on a private
 * disk and held until a malware scan says it's clean.
 */
final readonly class FormFiles
{
    public const MAX_FILES = 3;

    public const MAX_KB = 5120;

    /** Accepted types (decision B: images and PDFs only). HEIC isn't re-savable here; iPhones send JPEG to such forms. */
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(private TenantContext $context) {}

    /** A problem with the file, in words the visitor understands, or null when it's acceptable. */
    public function problem(UploadedFile $file): ?string
    {
        $mime = $this->sniff($file);
        if (! isset(self::TYPES[$mime])) {
            return 'Please attach a photo (JPEG, PNG or WebP) or a PDF.';
        }
        if ($mime === 'application/pdf' && $this->riskyPdf((string) $file->get())) {
            return 'That PDF contains scripts or attachments we can’t accept — please save it as a plain PDF.';
        }
        if ($mime !== 'application/pdf' && @imagecreatefromstring((string) $file->get()) === false) {
            return 'That image couldn’t be read — please try another.';
        }

        return null;
    }

    /** Store an accepted file for an enquiry (encrypted) and queue its scan. */
    public function store(UploadedFile $file, string $enquiryId): EnquiryFile
    {
        $mime = $this->sniff($file);
        $contents = $mime === 'application/pdf' ? (string) $file->get() : $this->resave((string) $file->get(), $mime);
        $path = $this->context->id().'/'.Str::ulid().'.bin';
        Storage::disk('form-files')->put($path, Crypt::encrypt($contents, false));
        $row = EnquiryFile::query()->create([
            'enquiry_id' => $enquiryId, 'name' => $this->safeName($file->getClientOriginalName(), self::TYPES[$mime]),
            'mime' => $mime, 'size' => strlen($contents), 'path' => $path, 'status' => 'pending',
        ]);
        ScanFormFile::dispatch($this->context->id(), $row->id)->afterCommit();

        return $row;
    }

    /** The decrypted contents (only for files scanned clean). */
    public function read(EnquiryFile $file): ?string
    {
        return $file->status !== 'clean' || $file->path === null ? null : (string) Crypt::decrypt((string) Storage::disk('form-files')->get($file->path), false);
    }

    /** Delete a file's contents, keeping the record of it (infected, or archived and expired). */
    public function remove(EnquiryFile $file, string $status): void
    {
        if ($file->path !== null) {
            Storage::disk('form-files')->delete($file->path);
        }
        $file->update(['path' => null, 'status' => $status]);
    }

    private function sniff(UploadedFile $file): string
    {
        return (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) $file->get()); // from the contents, never the name
    }

    private function riskyPdf(string $pdf): bool
    {
        return preg_match('#/(JavaScript|JS|Launch|EmbeddedFile|RichMedia|XFA|OpenAction)\b#', $pdf) === 1;
    }

    /** Re-save an image through GD: only the pixels survive (no metadata, location, or trailing content). */
    private function resave(string $bytes, string $mime): string
    {
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new \InvalidArgumentException('That image couldn’t be read — please try another.');
        }
        ob_start();
        match ($mime) {
            'image/png' => imagepng($img, null, 6),
            'image/webp' => imagewebp($img, null, 85),
            default => imagejpeg($img, null, 88),
        };
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function safeName(string $name, string $extension): string
    {
        $base = trim((string) preg_replace('/[^\pL\pN ._-]+/u', '', pathinfo($name, PATHINFO_FILENAME))) ?: 'file';

        return mb_substr($base, 0, 100).'.'.$extension;
    }
}
