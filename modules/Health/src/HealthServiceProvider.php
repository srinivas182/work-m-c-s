<?php

declare(strict_types=1);

namespace Wurk\Health;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Wurk\Health\Actions\RunHealthChecks;
use Wurk\Health\Checks\CacheCheck;
use Wurk\Health\Checks\DatabaseCheck;
use Wurk\Health\Contracts\HealthCheck;
use Wurk\Health\Http\Controllers\LivenessController;
use Wurk\Health\Http\Controllers\ReadinessController;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class HealthServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->tag([DatabaseCheck::class, CacheCheck::class], HealthCheck::TAG);

        $this->app->bind(
            RunHealthChecks::class,
            fn (Application $app): RunHealthChecks => new RunHealthChecks($app->tagged(HealthCheck::TAG)),
        );
    }

    /**
     * Health endpoints are stateless: no session, cookies or CSRF.
     */
    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::prefix('health')->name('health.')->group(function (): void {
            Route::get('live', LivenessController::class)->name('live');
            Route::get('ready', ReadinessController::class)->name('ready');
        });
    }
}
