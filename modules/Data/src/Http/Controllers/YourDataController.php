<?php

declare(strict_types=1);

namespace Wurk\Data\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Data\Jobs\BuildExportJob;
use Wurk\Data\Models\DataExport;
use Wurk\Data\Support\Exporter;
use Wurk\Tenancy\Contracts\TenantContext;

/** Settings → Your data (Sprint 16.6): ask for a full export; download it (signed link, 24 hours, signed in). */
final class YourDataController
{
    public const PER_DAY = 3;

    public function __construct(private readonly TenantContext $context) {}

    public function show(Exporter $exporter): Response
    {
        $tz = $this->context->current()->timezone();

        return Inertia::render('Data::YourData', [
            'hours' => Exporter::HOURS,
            'exports' => DataExport::query()->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get()->map(fn (DataExport $e) => [
                'id' => $e->id, 'status' => $e->status === 'ready' && $e->expires_at?->isPast() ? 'expired' : $e->status,
                'size' => $e->size, 'contents' => $e->contents, 'requested' => $e->created_at?->setTimezone($tz)->toIso8601String(),
                'expires' => $e->expires_at?->setTimezone($tz)->toIso8601String(),
                'download' => $e->status === 'ready' && $e->expires_at?->isFuture() ? $exporter->link($e) : null,
            ])->all(),
        ]);
    }

    public function store(Request $request, AuditLog $audit): RedirectResponse
    {
        if (DataExport::query()->where('status', 'queued')->exists()) {
            return back()->with('warning', 'An export is already being built — we’ll email you when it’s ready.');
        }
        if (DataExport::query()->where('created_at', '>=', now()->subDay())->count() >= self::PER_DAY) {
            return back()->with('warning', 'That’s '.self::PER_DAY.' exports in a day — please try again tomorrow.');
        }
        $export = DataExport::query()->create(['requested_by' => (string) $request->user()?->getAuthIdentifier(), 'status' => 'queued']);
        $audit->record('data.export_requested', 'data_export', $export->id);
        BuildExportJob::dispatch($this->context->id(), $export->id);

        return back()->with('status', 'We’re building your export. We’ll email you a download link (it works for '.Exporter::HOURS.' hours).');
    }

    public function download(string $export, AuditLog $audit): StreamedResponse
    {
        $e = DataExport::query()->whereKey($export)->where('status', 'ready')->where('expires_at', '>', now())->firstOrFail();
        $e->increment('downloads');
        $audit->record('data.export_downloaded', 'data_export', $e->id);
        $name = 'wurk-'.$this->context->current()->handle().'-'.$e->created_at?->format('Y-m-d').'.zip';

        return $this->context->disk()->download((string) $e->path, $name, ['Content-Type' => 'application/zip']);
    }
}
