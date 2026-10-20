<?php

declare(strict_types=1);

namespace Wurk\Platform\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Platform\Modules\ModuleRegistry;

/**
 * Temporary landing page until the wurk.co.za marketing site ships (Sprint 3).
 */
final class WelcomeController
{
    public function __invoke(ModuleRegistry $registry, Application $app): Response
    {
        $modules = $app->environment('production') ? [] : array_values(array_map(
            fn ($m) => ['name' => $m->name, 'version' => $m->version, 'description' => $m->description],
            $registry->all(),
        ));

        return Inertia::render('Platform::Welcome', [
            'modules' => $modules,
        ]);
    }
}
