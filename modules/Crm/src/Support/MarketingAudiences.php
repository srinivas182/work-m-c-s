<?php

declare(strict_types=1);

namespace Wurk\Crm\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Crm\Contracts\Audiences;
use Wurk\Tenancy\Contracts\TenantContext;

final readonly class MarketingAudiences implements Audiences
{
    public const SEGMENTS = ['all', 'recent', 'inactive', 'branch', 'contacts'];

    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function audience(string $segment, ?string $branchId = null, ?array $contactIds = null): array
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $q = $db->table('contacts')->where('contacts.tenant_id', $t)->whereNotNull('contacts.marketing_consent_at') // consented only
            ->where(fn ($q) => $q->whereNotNull('contacts.email')->orWhereNotNull('contacts.phone'));
        $lastSeen = fn () => $db->table('contact_links')->where('tenant_id', $t)->selectRaw('contact_id, max(occurred_at) as last_at')->groupBy('contact_id');
        match ($segment) {
            'recent' => $q->joinSub($lastSeen(), 'seen', 'seen.contact_id', '=', 'contacts.id')->where('seen.last_at', '>=', now()->subDays(30)),
            'inactive' => $q->joinSub($lastSeen(), 'seen', 'seen.contact_id', '=', 'contacts.id')->where('seen.last_at', '<', now()->subDays(60)),
            'branch' => $q->whereIn('contacts.id', $db->table('contact_links')->where('tenant_id', $t)->where('branch_id', (string) $branchId)->select('contact_id')),
            'contacts' => $q->whereIn('contacts.id', $contactIds ?? []),
            default => null,
        };

        return array_values($q->orderBy('contacts.name')->get(['contacts.id', 'contacts.name', 'contacts.email', 'contacts.phone'])
            ->map(fn ($c) => ['id' => (string) $c->id, 'name' => (string) $c->name, 'email' => $c->email, 'phone' => $c->phone])->all());
    }

    public function withdrawConsent(string $contactId, string $how): void
    {
        $changed = DB::connection($this->context->connectionName())->table('contacts')->where('tenant_id', $this->context->id())->where('id', $contactId)
            ->whereNotNull('marketing_consent_at')->update(['marketing_consent_at' => null, 'marketing_consent_source' => null]);
        if ($changed > 0) {
            $this->audit->record('contacts.consent_withdrawn', 'contact', $contactId, ['how' => $how]); // ids only (decision H)
        }
    }

    public function hasConsent(string $contactId): bool
    {
        return DB::connection($this->context->connectionName())->table('contacts')->where('tenant_id', $this->context->id())->where('id', $contactId)
            ->whereNotNull('marketing_consent_at')->exists();
    }
}
