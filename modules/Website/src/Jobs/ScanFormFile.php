<?php

declare(strict_types=1);

namespace Wurk\Website\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\EnquiryFile;
use Wurk\Website\Support\Uploads\FormFiles;
use Wurk\Website\Support\Uploads\VirusScanner;

/** Scan a visitor's file: clean → released; infected → deleted and logged; scanner unreachable → held, tried again. */
final class ScanFormFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 48; // ~a day of retries if the scanner is down; the file stays held meanwhile

    public function __construct(public readonly string $tenantId, public readonly string $fileId) {}

    public function backoff(): int
    {
        return 1800;
    }

    public function handle(TenantDirectory $tenants, TenantContext $context, VirusScanner $scanner): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function () use ($scanner): void {
            $file = EnquiryFile::query()->find($this->fileId);
            if ($file === null || $file->status !== 'pending' || $file->path === null) {
                return;
            }
            $verdict = $scanner->scan((string) Crypt::decrypt((string) Storage::disk('form-files')->get($file->path), false));
            if ($verdict === null) {
                $this->release($this->backoff()); // the scanner is down: keep holding the file

                return;
            }
            if ($verdict === VirusScanner::INFECTED) {
                app(FormFiles::class)->remove($file, 'infected');
                app(AuditLog::class)->record('website.upload_infected', 'enquiry_file', $file->id, ['enquiry' => $file->enquiry_id]);
            } else {
                $file->update(['status' => 'clean']);
            }
            $file->update(['scanned_at' => now()]);
        });
    }
}
