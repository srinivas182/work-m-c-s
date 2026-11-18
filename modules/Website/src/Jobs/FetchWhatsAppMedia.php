<?php

declare(strict_types=1);

namespace Wurk\Website\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Support\Uploads\VirusScanner;
use Wurk\Website\Support\WhatsAppChats;

/**
 * Fetches a photo, document or voice note a customer sent on WhatsApp (Sprint 21.2), scans it, and keeps it privately.
 * Infected files are never kept; if the scanner is down, the job waits and tries again (Meta keeps media for 30 days).
 */
final class FetchWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_BYTES = 25 * 1024 * 1024;

    public int $tries = 24;

    public function __construct(public readonly string $tenantId, public readonly string $messageId, public readonly string $mediaId,
        public readonly ?string $mime, public readonly ?string $filename) {}

    public function backoff(): int
    {
        return 900;
    }

    public function handle(TenantDirectory $tenants, TenantContext $context, VirusScanner $scanner): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function () use ($scanner): void {
            $message = ReceptionistMessage::query()->find($this->messageId);
            if ($message === null || $message->attachment !== null) {
                return;
            }
            $media = app(WhatsAppBusiness::class)->media($this->mediaId);
            if ($media === null) {
                $message->update(['attachment' => ['blocked' => 'unavailable']]);

                return;
            }
            if (strlen($media['contents']) > self::MAX_BYTES) {
                $message->update(['attachment' => ['blocked' => 'too_large']]);

                return;
            }
            $verdict = $scanner->scan($media['contents']);
            if ($verdict === null) {
                $this->release($this->backoff());

                return;
            }
            if ($verdict === VirusScanner::INFECTED) {
                $message->update(['attachment' => ['blocked' => 'infected']]);
                app(AuditLog::class)->record('website.whatsapp_file_infected', 'receptionist_message', $message->id, []);

                return;
            }
            $mime = $this->mime ?? $media['mime'];
            $kind = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'audio/') ? 'audio' : (str_starts_with($mime, 'video/') ? 'video' : 'document'));
            $message->update(['attachment' => ['path' => app(WhatsAppChats::class)->keep($media['contents'], $mime), 'mime' => $mime,
                'name' => mb_substr($this->filename ?? $kind, 0, 120), 'bytes' => strlen($media['contents']), 'kind' => $kind]]);
        });
    }
}
