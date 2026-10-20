<?php

declare(strict_types=1);

namespace Wurk\Platform\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal showcase of every design token and component (not available in production).
 */
final class DesignSystemController
{
    public function __invoke(): Response
    {
        return Inertia::render('Platform::DesignSystem');
    }
}
