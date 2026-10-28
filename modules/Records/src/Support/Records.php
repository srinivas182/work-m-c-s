<?php

declare(strict_types=1);

namespace Wurk\Records\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Wurk\Records\Models\Document;
use Wurk\Records\Models\Patient;
use Wurk\Records\Models\RecordLink;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Patient documents (POPIA special personal information): files encrypted before they're stored on a
 * private disk; never deleted during retention — a correction adds a new version; every access logged.
 * After the retention period a patient is flagged, and the owner may remove their records (logged).
 */
final readonly class Records
{
    public function __construct(private TenantContext $context) {}

    public function retentionYears(): int
    {
        return (int) config('records.retention_years', 6);
    }

    /** The day a patient's records may be removed. */
    public function keptUntil(Patient $patient): ?Carbon
    {
        return $patient->last_document_at?->copy()->addYears($this->retentionYears());
    }

    public function upload(Patient $patient, string $title, UploadedFile $file, string $by, ?Document $corrects = null, ?string $note = null): Document
    {
        $path = $this->context->id().'/'.$patient->id.'/'.Str::ulid().'.bin';
        Storage::disk('records')->put($path, Crypt::encrypt((string) $file->get(), false)); // encrypted at rest
        $original = $corrects === null ? null : ($corrects->original_id ?? $corrects->id);
        $version = $original === null ? 1 : 1 + (int) Document::query()->where(fn ($q) => $q->where('id', $original)->orWhere('original_id', $original))->max('version');

        return DB::connection($this->context->connectionName())->transaction(function () use ($patient, $title, $file, $by, $path, $original, $version, $note): Document {
            $doc = Document::query()->create([
                'patient_id' => $patient->id, 'original_id' => $original, 'version' => $version, 'title' => $title, 'note' => $note,
                'path' => $path, 'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'), 'size' => (int) $file->getSize(), 'uploaded_by' => $by,
            ]);
            $patient->update(['last_document_at' => now(), 'flagged_at' => null]); // retention runs from the most recent document
            DB::table('record_retention')->updateOrInsert(['patient_id' => $patient->id], ['tenant_id' => $this->context->id(), 'last_document_at' => now()]);
            $this->log($patient->id, $doc->id, $original === null ? 'upload' : 'correct', 'staff', $by);

            return $doc;
        });
    }

    /** The file's contents (decrypted), logged as a view or download. */
    public function read(Document $doc, string $action, string $actorType, ?string $actor, ?string $ip = null): string
    {
        $this->log($doc->patient_id, $doc->id, $action, $actorType, $actor, $ip);

        return (string) Crypt::decrypt((string) Storage::disk('records')->get($doc->path), false);
    }

    /** Mark this business's patients past the retention period (nothing is deleted). */
    public function flagDue(): int
    {
        $cutoff = now()->subYears($this->retentionYears());
        $n = 0;
        foreach (Patient::query()->whereNull('flagged_at')->where('last_document_at', '<', $cutoff)->get() as $p) {
            $p->update(['flagged_at' => now()]);
            $this->log($p->id, null, 'flagged', 'system', null);
            $n++;
        }

        return $n;
    }

    /** Remove a flagged patient's records — only after retention, by the owner; the removal is logged. */
    public function remove(Patient $patient, string $by): void
    {
        $until = $this->keptUntil($patient);
        if ($patient->flagged_at === null || $until === null || $until->isFuture()) {
            throw new RuntimeException('These records must be kept until '.($until?->format('j F Y') ?? 'the retention period ends').'.');
        }
        foreach (Document::query()->where('patient_id', $patient->id)->get() as $doc) {
            Storage::disk('records')->delete($doc->path);
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($patient, $by): void {
            Document::query()->where('patient_id', $patient->id)->delete();
            RecordLink::query()->where('patient_id', $patient->id)->delete();
            $this->log($patient->id, null, 'removed', 'staff', $by); // the log keeps that removal happened, not what was removed
            $patient->delete();
        });
        DB::table('record_retention')->where('patient_id', $patient->id)->delete();
    }

    public function log(string $patientId, ?string $documentId, string $action, string $actorType, ?string $actor, ?string $ip = null): void
    {
        DB::connection($this->context->connectionName())->table('record_access_logs')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'patient_id' => $patientId, 'document_id' => $documentId,
            'action' => $action, 'actor_type' => $actorType, 'actor' => $actor, 'ip' => $ip, 'created_at' => now(),
        ]);
    }
}
