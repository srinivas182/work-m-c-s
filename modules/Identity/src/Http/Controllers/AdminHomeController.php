<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/** Placeholder until the super admin dashboard (Sprint 2 / 13). */
final class AdminHomeController
{
    public function __invoke(): Response
    {
        return Inertia::render('Identity::AdminHome');
    }
}
