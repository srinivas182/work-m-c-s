<?php

declare(strict_types=1);

namespace Wurk\Identity\People;

use Illuminate\Support\Facades\DB;
use Wurk\Identity\Contracts\TeamActivity;

final class EloquentTeamActivity implements TeamActivity
{
    public function lastSignIn(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $seen = DB::table('memberships')->join('identities', 'identities.id', '=', 'memberships.identity_id')
            ->whereIn('memberships.tenant_id', $tenantIds)->where('memberships.kind', 'staff')->where('memberships.status', 'active')
            ->selectRaw('memberships.tenant_id, max(identities.last_login_at) as seen')->groupBy('memberships.tenant_id')->pluck('seen', 'tenant_id');

        return array_combine($tenantIds, array_map(fn ($t) => isset($seen[$t]) ? (string) $seen[$t] : null, $tenantIds));
    }
}
