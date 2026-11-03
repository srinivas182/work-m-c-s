<?php

declare(strict_types=1);

namespace Wurk\Website\Console;

use Illuminate\Console\Command;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\Enquiry;
use Wurk\Website\Models\EnquiryFile;
use Wurk\Website\Support\Uploads\FormFiles;

/** Daily: files of archived enquiries are removed after the retention period (12 months); each removal is logged. */
final class ExpireFormFilesCommand extends Command
{
    protected $signature = 'website:expire-form-files';

    protected $description = 'Remove files attached to archived enquiries after the retention period';

    public function handle(TenantDirectory $directory, TenantContext $context): int
    {
        $months = (int) config('website.form_files_months', 12);
        $removed = 0;
        foreach ($directory->activeIds() as $id) {
            $tenant = $directory->find($id);
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($months, &$removed): void {
                $archived = Enquiry::query()->where('status', 'archived')->where('created_at', '<', now()->subMonths($months))->pluck('id');
                foreach (EnquiryFile::query()->whereIn('enquiry_id', $archived)->whereNotNull('path')->get() as $file) {
                    app(FormFiles::class)->remove($file, 'removed');
                    app(AuditLog::class)->record('website.upload_expired', 'enquiry_file', $file->id, ['enquiry' => $file->enquiry_id]);
                    $removed++;
                }
            });
        }
        $this->info("Form files removed: {$removed}.");

        return self::SUCCESS;
    }
}
