<?php

declare(strict_types=1);

namespace Wurk\Support;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Wurk\Platform\Contracts\HealthSignals;
use Wurk\Platform\Contracts\SupportSessions;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Support\Http\Middleware\GuardSupportSession;
use Wurk\Support\Http\Middleware\ShareSupport;
use Wurk\Support\Support\SupportAccess;
use Wurk\Support\Support\TicketSignal;
use Wurk\Tenancy\Events\TenantEntered;

final class SupportServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        // One per request: the answer is asked on every permission check (and never outlives the request in Octane).
        $this->app->scoped(SupportAccess::class);
        $this->app->scoped(SupportSessions::class, fn ($app) => $app->make(SupportAccess::class));
    }

    public function menu(): array
    {
        return [
            ['label' => 'Contact support', 'route' => 'tenant.support', 'icon' => 'life-buoy', 'section' => 'Settings', 'order' => 990],
            ['label' => 'Support access', 'route' => 'tenant.support.access', 'icon' => 'shield-check', 'section' => 'Settings', 'permission' => 'support.access', 'order' => 991],
            ['label' => 'Support inbox', 'route' => 'admin.support', 'icon' => 'life-buoy', 'section' => 'Platform', 'permission' => 'support.manage', 'order' => 55],
        ];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'support.tickets', 'label' => 'See and reply to every support ticket from this business'],
            ['key' => 'support.access', 'label' => 'Let WURK support into the back office (approve, decline, end)'],
            ['key' => 'support.manage', 'label' => 'Answer support tickets from businesses', 'scope' => 'platform'],
        ];
    }

    protected function bootModule(): void
    {
        $this->callAfterResolving(HealthSignals::class, fn (HealthSignals $s) => $s->register('support', fn (string $t) => $this->app->make(TicketSignal::class)($t))); // business health (16.5)
        $this->loadViewsFrom($this->modulePath('resources/views'), 'support');
        $this->app->make(Router::class)->pushMiddlewareToGroup('tenant.back-office', ShareSupport::class);
        $this->app->make(Router::class)->pushMiddlewareToGroup('tenant.back-office', GuardSupportSession::class);
        Event::listen(TenantEntered::class, fn () => $this->app->make(SupportAccess::class)->forget());
    }
}
