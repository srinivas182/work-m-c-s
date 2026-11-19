<?php

declare(strict_types=1);

namespace Wurk\Trust\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Platform\Contracts\BusinessBadge;
use Wurk\Platform\Events\BadgeChanged;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Trust\Models\BusinessVerification;

/**
 * Verified business (Sprint 25.4).
 *
 * - "Verified by WURK": the owner's phone was confirmed with a code, and WURK HQ checked their ID document against a
 *   selfie in the last two years. Sole traders qualify — no company needed.
 * - "Registered business": HQ matched the company or close-corporation number with the registry (CIPC in South Africa).
 * - Documents are encrypted on the private "trust" disk, seen only by HQ (every view logged) and deleted 90 days after
 *   the decision; only the decision is kept. Never shown for a suspended business.
 */
final class Verifications implements BusinessBadge
{
    public const IDENTITY_YEARS = 2;

    public const KEEP_DAYS = 90;

    public const DISK = 'trust';

    /** South African companies and close corporations: 2019/123456/07. Other countries: letters, digits and / - . (with a digit). */
    public const REGISTRY = '/^(\d{4}\/\d{6}\/\d{2}|(?=[A-Z\/.\-]*\d)[A-Z0-9][A-Z0-9\/.\-]{3,28})$/i';

    public function __construct(private TenantContext $context, private TenantDirectory $tenants, private TeamDirectory $team, private PeopleDirectory $people,
        private AuditLog $audit) {}

    public function for(string $tenantId): array
    {
        $tenant = $this->tenants->find($tenantId);
        $v = BusinessVerification::query()->find($tenantId);
        $url = 'https://'.config('wurk.domains.root').'/verified/'.($tenant?->handle() ?? '');
        if ($tenant === null || ! $tenant->isActive() || $v === null) {
            return ['verified' => false, 'registered' => false, 'checked_on' => null, 'url' => $url];
        }
        $identity = $v->identity_status === 'approved' && $v->identity_expires_at !== null && $v->identity_expires_at->isFuture();
        $verified = $identity && $this->context->run($tenant, fn () => $this->ownerPhoneVerified());

        return ['verified' => $verified, 'registered' => $verified && $v->registry_status === 'approved',
            'checked_on' => $verified ? $v->identity_decided_at?->toDateString() : null, 'url' => $url];
    }

    /** Inside the business: whether one of its active owners has proved their mobile number. */
    public function ownerPhoneVerified(): bool
    {
        foreach ($this->team->roster() as $m) {
            if ($m['role'] === 'owner' && $m['active'] && $this->people->verifiedPhone($m['identity_id']) !== null) {
                return true;
            }
        }

        return false;
    }

    public function current(): BusinessVerification
    {
        return BusinessVerification::query()->find($this->context->id()) ?? new BusinessVerification(['tenant_id' => $this->context->id()]);
    }

    /** The owner sends their ID document and a selfie: encrypted, then waiting for HQ. */
    public function submitIdentity(UploadedFile $id, UploadedFile $selfie, string $by): void
    {
        $v = $this->current();
        if ($v->identity_status === 'pending') {
            throw ValidationException::withMessages(['id_document' => __('WURK is still checking what you sent.')]);
        }
        $this->deleteDocuments($v);
        $documents = [];
        foreach (['id' => $id, 'selfie' => $selfie] as $kind => $file) {
            $path = $this->context->id().'/'.Str::ulid().'.bin';
            Storage::disk(self::DISK)->put($path, Crypt::encrypt((string) $file->get(), false)); // encrypted at rest
            $documents[] = ['kind' => $kind, 'path' => $path, 'mime' => (string) $file->getMimeType()];
        }
        $v->fill(['identity_status' => 'pending', 'identity_submitted_at' => now(), 'identity_reason' => null, 'documents' => $documents, 'documents_delete_after' => null])->save();
        $this->audit->record('trust.identity_submitted', 'business', $this->context->id(), ['by' => $by]);
    }

    public function submitRegistry(string $number, string $by): void
    {
        $number = strtoupper(trim($number));
        if (preg_match(self::REGISTRY, $number) !== 1) {
            throw ValidationException::withMessages(['registry_number' => __('Type the registration number as it appears on your certificate, e.g. 2019/123456/07.')]);
        }
        $v = $this->current();
        $v->fill(['registry_number' => $number, 'registry_status' => 'pending', 'registry_submitted_at' => now(), 'registry_reason' => null])->save();
        $this->audit->record('trust.registry_submitted', 'business', $this->context->id(), ['number' => $number, 'by' => $by]);
    }

    /** HQ's decision on the identity check. Documents are deleted 90 days after it either way. */
    public function decideIdentity(string $tenantId, bool $approve, ?string $reason, string $by): void
    {
        $v = BusinessVerification::query()->findOrFail($tenantId);
        if ($v->identity_status !== 'pending') {
            throw ValidationException::withMessages(['decision' => __('This check isn’t waiting for a decision.')]);
        }
        if (! $approve && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Say why, so the owner can put it right.')]);
        }
        $v->fill(['identity_status' => $approve ? 'approved' : 'refused', 'identity_decided_at' => now(), 'identity_expires_at' => $approve ? now()->addYears(self::IDENTITY_YEARS) : null,
            'identity_reason' => $approve ? null : mb_substr(trim((string) $reason), 0, 300), 'decided_by' => $by, 'documents_delete_after' => now()->addDays(self::KEEP_DAYS)])->save();
        $this->audit->record($approve ? 'trust.identity_approved' : 'trust.identity_refused', 'business', $tenantId, ['by' => $by, 'reason' => $v->identity_reason], ['platform' => true]);
        event(new BadgeChanged($tenantId));
    }

    public function decideRegistry(string $tenantId, bool $approve, ?string $reason, string $by): void
    {
        $v = BusinessVerification::query()->findOrFail($tenantId);
        if ($v->registry_status !== 'pending') {
            throw ValidationException::withMessages(['decision' => __('This check isn’t waiting for a decision.')]);
        }
        if (! $approve && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Say why, so the owner can put it right.')]);
        }
        $v->fill(['registry_status' => $approve ? 'approved' : 'refused', 'registry_decided_at' => now(), 'registry_reason' => $approve ? null : mb_substr(trim((string) $reason), 0, 300),
            'decided_by' => $by])->save();
        $this->audit->record($approve ? 'trust.registry_approved' : 'trust.registry_refused', 'business', $tenantId, ['by' => $by, 'number' => $v->registry_number], ['platform' => true]);
        event(new BadgeChanged($tenantId));
    }

    /**
     * A document for HQ to look at (every view is logged).
     *
     * @return array{content: string, mime: string}
     */
    public function document(string $tenantId, string $kind, string $by): array
    {
        $doc = collect(BusinessVerification::query()->findOrFail($tenantId)->documents ?? [])->firstWhere('kind', $kind) ?? abort(404);
        $this->audit->record('trust.document_viewed', 'business', $tenantId, ['kind' => $kind, 'by' => $by], ['platform' => true]);

        return ['content' => (string) Crypt::decrypt((string) Storage::disk(self::DISK)->get($doc['path']), false), 'mime' => $doc['mime']];
    }

    /** Deletes documents kept past their date (90 days after the decision). Returns how many businesses. */
    public function purge(): int
    {
        $n = 0;
        foreach (BusinessVerification::query()->whereNotNull('documents')->where('documents_delete_after', '<=', now())->get() as $v) {
            $this->deleteDocuments($v);
            $v->fill(['documents' => null, 'documents_delete_after' => null])->save();
            $n++;
        }

        return $n;
    }

    private function deleteDocuments(BusinessVerification $v): void
    {
        foreach ($v->documents ?? [] as $doc) {
            Storage::disk(self::DISK)->delete($doc['path']);
        }
    }
}
