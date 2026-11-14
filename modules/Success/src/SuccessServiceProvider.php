<?php

declare(strict_types=1);

namespace Wurk\Success;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\DB;
use Throwable;
use Wurk\Platform\Contracts\HealthSignals;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Success\Console\DigestCommand;
use Wurk\Success\Console\ScoreCommand;
use Wurk\Success\Support\HealthScores;
use Wurk\Tenancy\Contracts\TenantContext;

final class SuccessServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [['label' => 'Business health', 'route' => 'admin.health', 'icon' => 'heart-pulse', 'section' => 'Platform', 'permission' => 'health.view', 'order' => 12]];
    }

    public function permissions(): array
    {
        return [['key' => 'health.view', 'label' => 'See every business’s health score and why', 'scope' => 'platform']];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'success');
        if ($this->app->runningInConsole()) {
            $this->commands([ScoreCommand::class, DigestCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('success:score')->dailyAt('02:37')->onOneServer()->withoutOverlapping();
            $s->command('success:weekly-digest')->weeklyOn(1, '06:41')->onOneServer();
        });

        // Server errors, counted for the business they happened in (no details kept — the logs have those).
        $handler = $this->app->make(ExceptionHandler::class);
        if ($handler instanceof Handler) {
            $handler->reportable(function (Throwable $e): void {
                $context = $this->app->make(TenantContext::class);
                if ($context->has()) {
                    try {
                        HealthScores::countError($context->id());
                    } catch (Throwable) {
                        // never let counting an error cause another
                    }
                }
            });
        }

        $this->callAfterResolving(HealthSignals::class, fn (HealthSignals $s) => $s->register('success', function (string $tenantId): array {
            $errors = (int) DB::table('business_errors_daily')->where('tenant_id', $tenantId)->where('day', '>=', now()->subDays(6)->toDateString())->sum('count');

            return $errors < 10 ? [] : [['key' => 'errors', 'points' => $errors >= 50 ? 20 : 10, 'reason' => "{$errors} server errors in the last 7 days",
                'action' => 'Check the error logs for this business before they contact us']];
        }));
    }
}
