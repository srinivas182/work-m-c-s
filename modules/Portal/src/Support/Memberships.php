<?php

declare(strict_types=1);

namespace Wurk\Portal\Support;

use Illuminate\Support\Facades\DB;

/** My WURK's central index (Sprint 13.8): which businesses a WURK ID has a portal account with — nothing else. */
final class Memberships
{
    public function add(string $identityId, string $tenantId): void
    {
        DB::table('customer_memberships')->insertOrIgnore(['identity_id' => $identityId, 'tenant_id' => $tenantId, 'created_at' => now()]);
    }

    public function remove(string $identityId, string $tenantId): void
    {
        DB::table('customer_memberships')->where('identity_id', $identityId)->where('tenant_id', $tenantId)->delete();
    }

    public function hide(string $identityId, string $tenantId): void
    {
        DB::table('customer_memberships')->where('identity_id', $identityId)->where('tenant_id', $tenantId)->update(['hidden_at' => now()]);
    }

    /** @return list<string> tenant ids, in the order they joined */
    public function businesses(string $identityId): array
    {
        return array_values(DB::table('customer_memberships')->where('identity_id', $identityId)->whereNull('hidden_at')->orderBy('created_at')->pluck('tenant_id')->map(fn ($t) => (string) $t)->all());
    }
}
