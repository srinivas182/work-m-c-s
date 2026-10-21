<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Contracts\TenantContext;

/** Placeholder business home until the tenant dashboard is built. */
final class WorkspaceController
{
    public function __invoke(TenantContext $context): Response
    {
        $tenant = $context->current();

        return Inertia::render('Identity::Workspace', [
            'business' => ['name' => $tenant->displayName(), 'handle' => $tenant->handle()],
        ]);
    }
}
