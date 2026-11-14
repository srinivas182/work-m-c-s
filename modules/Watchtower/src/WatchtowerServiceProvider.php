<?php

declare(strict_types=1);

namespace Wurk\Watchtower;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Throwable;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Watchtower\Console\CheckCommand;
use Wurk\Watchtower\Console\DigestCommand;
use Wurk\Watchtower\Console\SweepCommand;
use Wurk\Watchtower\Http\Controllers\ClientErrorController;
use Wurk\Watchtower\Http\Controllers\HeartbeatController;
use Wurk\Watchtower\Support\Heartbeats;
use Wurk\Watchtower\Support\Recorder;
use Wurk\Watchtower\Support\Signal;
use Wurk\Watchtower\Support\Where;

/**
 * Watchtower (Sprint 18.0): watches the whole platform for WURK HQ — server and browser errors, failed jobs, scheduled
 * tasks that fail or stop, queue backlogs, databases/shards/cache/storage — groups them into problems, and emails HQ
 * with a fix request ready to paste. The external uptime checker covers the one thing it can't: WURK being down.
 */
final class WatchtowerServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [['label' => 'Watchtower', 'route' => 'admin.watchtower', 'icon' => 'radar', 'section' => 'Platform', 'permission' => 'watchtower.view', 'order' => 13]];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'watchtower.view', 'label' => 'See problems on the platform (errors, failed jobs, system checks)', 'scope' => 'platform'],
            ['key' => 'watchtower.manage', 'label' => 'Mark problems fixed or ignored, and choose who gets problem emails', 'scope' => 'platform'],
        ];
    }

    public function register(): void
    {
        $this->app->scoped(Recorder::class);
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'watchtower');
        if ($this->app->runningInConsole()) {
            $this->commands([CheckCommand::class, DigestCommand::class, SweepCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('watchtower:check')->everyMinute()->onOneServer()->withoutOverlapping(5);
            $s->command('watchtower:digest')->dailyAt('08:00')->timezone('Africa/Johannesburg')->onOneServer();
            $s->command('watchtower:sweep')->dailyAt('03:47')->onOneServer();
        });

        // Every reported server error (web, queue, console) becomes — or counts towards — a problem.
        $handler = $this->app->make(ExceptionHandler::class);
        if ($handler instanceof Handler) {
            $handler->reportable(function (Throwable $e): void {
                try {
                    $request = $this->app->runningInConsole() && ! $this->app->runningUnitTests() ? null : request();
                    $worker = str_starts_with((string) ($_SERVER['argv'][1] ?? ''), 'queue:');
                    $this->app->make(Recorder::class)->record(Signal::fromThrowable($e, 'exception', $request !== null ? Where::location($request) : null,
                        $request !== null ? Where::surface($request) : ($worker ? 'queue' : 'console')));
                } catch (Throwable) {
                    // never let recording an error cause another
                }
            });
        }

        // A job that has used up its retries.
        Event::listen(JobFailed::class, function (JobFailed $e): void {
            $job = mb_substr((string) $e->job->resolveName(), 0, 200);
            $this->app->make(Recorder::class)->record(Signal::fromThrowable($e->exception, 'job', "job {$job}", 'queue', ['queue' => $e->job->getQueue(), 'attempts' => $e->job->attempts()], 'high'));
        });

        Event::listen(ScheduledTaskStarting::class, fn (ScheduledTaskStarting $e) => $this->app->make(Heartbeats::class)->starting($e));
        Event::listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $e) => $this->app->make(Heartbeats::class)->finished($e));
        Event::listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $e) => $this->app->make(Heartbeats::class)->failed($e));
    }

    /** Plus two stateless endpoints: the scheduler's pulse for the uptime checker, and browser crash reports. */
    protected function registerRoutes(): void
    {
        parent::registerRoutes();
        if ($this->app->routesAreCached()) {
            return;
        }
        Route::get('health/heartbeat', HeartbeatController::class)->name('health.heartbeat');
        Route::post('_wurk/client-error', ClientErrorController::class)->middleware('throttle:20,1,client-error')->name('watchtower.client-error');
    }
}
