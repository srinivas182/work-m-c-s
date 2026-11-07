<?php

declare(strict_types=1);

namespace Wurk\Portal\Support;

use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Who is signed in to this business's portal. Needs all of: a signed-in WURK ID, a session started for THIS business
 * (signing in at one business's portal means nothing at another's), and a portal link to their customer record.
 */
final readonly class CustomerSession
{
    public const KEY = 'portal.tenant';

    public function __construct(private TenantContext $context) {}

    /** @return array{id: string, identity_id: string, contact_id: string, consented: bool}|null */
    public function current(Request $request): ?array
    {
        $identity = Auth::id();
        if ($identity === null || $request->session()->get(self::KEY) !== $this->context->id()) {
            return null;
        }
        $row = $this->db()->table('portal_customers')->where('tenant_id', $this->context->id())->where('identity_id', (string) $identity)->first();

        return $row === null ? null : ['id' => (string) $row->id, 'identity_id' => (string) $row->identity_id, 'contact_id' => (string) $row->contact_id, 'consented' => $row->consented_at !== null];
    }

    /** Start (or resume) this person's portal session at this business, linked to their customer record. */
    public function start(Request $request, string $identityId, string $contactId): void
    {
        $tenant = $this->context->id();
        $row = $this->db()->table('portal_customers')->where('tenant_id', $tenant)->where('identity_id', $identityId)->first();
        $row === null
            ? $this->db()->table('portal_customers')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $tenant, 'identity_id' => $identityId, 'contact_id' => $contactId,
                'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()])
            : $this->db()->table('portal_customers')->where('tenant_id', $tenant)->where('id', $row->id)->update(['last_seen_at' => now(), 'updated_at' => now()]);
        $request->session()->put(self::KEY, $tenant);
        app(Memberships::class)->add($identityId, $tenant); // My WURK's index (Sprint 13.8)
    }

    public function consent(string $portalId): void
    {
        $this->db()->table('portal_customers')->where('tenant_id', $this->context->id())->where('id', $portalId)->update(['consented_at' => now(), 'updated_at' => now()]);
    }

    private function db(): Connection
    {
        return DB::connection($this->context->connectionName());
    }
}
