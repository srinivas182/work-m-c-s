<?php

declare(strict_types=1);

namespace Wurk\Admin\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditFeed;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** WURK staff home. Revenue and SaaS metrics arrive with billing (Sprints 3 and 13). */
final class DashboardController
{
    public function __invoke(TenantDirectory $tenants, PeopleDirectory $people, AuditFeed $audit): Response
    {
        return Inertia::render('Admin::Dashboard', [
            'businesses' => $tenants->summary(),
            'staff' => $people->staffCount(),
            'recent' => $audit->recentPlatform(),
        ]);
    }
}
