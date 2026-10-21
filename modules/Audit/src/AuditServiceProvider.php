<?php

declare(strict_types=1);

namespace Wurk\Audit;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Wurk\Audit\Chain\EloquentAuditFeed;
use Wurk\Audit\Chain\Recorder;
use Wurk\Audit\Console\VerifyAuditCommand;
use Wurk\Audit\Contracts\AuditFeed;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Audit\Http\Middleware\AssignRequestId;
use Wurk\Audit\Listeners\RecordSecurityEvents;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class AuditServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLog::class, Recorder::class);
        $this->app->singleton(AuditFeed::class, EloquentAuditFeed::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'platform-audit.view', 'label' => 'See the platform audit log', 'scope' => 'platform'],
            ['key' => 'audit.view', 'label' => 'See the business audit log'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Audit log', 'route' => 'admin.audit', 'icon' => 'shield-check', 'section' => 'Platform', 'permission' => 'platform-audit.view', 'order' => 80],
            ['label' => 'Audit log', 'route' => 'tenant.audit', 'icon' => 'shield-check', 'section' => 'Settings', 'permission' => 'audit.view', 'order' => 800],
        ];
    }

    protected function bootModule(): void
    {
        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(AssignRequestId::class);
        }

        Event::subscribe(RecordSecurityEvents::class);

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditCommand::class]);
        }
    }
}
