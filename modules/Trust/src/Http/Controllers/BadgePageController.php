<?php

declare(strict_types=1);

namespace Wurk\Trust\Http\Controllers;

use Illuminate\Http\Response;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Trust\Support\Verifications;

/** wurk.co.za/verified/{handle} (25.4): what "Verified by WURK" means for this business — what was checked, and when. */
final class BadgePageController
{
    public function __invoke(string $handle, TenantDirectory $tenants, Verifications $verifications): Response
    {
        $tenant = $tenants->findByHandle($handle) ?? abort(404);
        $badge = $verifications->for($tenant->tenantId());

        return response()->view('trust::badge', ['business' => $tenant->displayName(), 'badge' => $badge])->header('Cache-Control', 'public, max-age=600');
    }
}
