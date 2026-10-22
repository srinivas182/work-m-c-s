<?php

declare(strict_types=1);

namespace Wurk\Billing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Wurk\Billing\Console\RenewSubscriptionsCommand;
use Wurk\Billing\Gateways\PaymentGateway;
use Wurk\Billing\Gateways\PaystackGateway;
use Wurk\Billing\Http\Middleware\RequirePayment;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class BillingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // First gateway (Sprint 3 decision 1); others implement the same contract.
        $this->app->singleton(PaymentGateway::class, PaystackGateway::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [['key' => 'billing.manage', 'label' => 'Manage billing, payments and credit purchases']];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Billing', 'route' => 'tenant.billing', 'icon' => 'credit-card', 'section' => 'Settings', 'permission' => 'billing.manage', 'order' => 770],
            ['label' => 'Credit packs', 'route' => 'admin.credits.packs', 'icon' => 'coins', 'section' => 'Settings', 'permission' => 'credits.manage', 'order' => 935],
        ];
    }

    protected function bootModule(): void
    {
        $this->app->make(Router::class)->pushMiddlewareToGroup('tenant.back-office', RequirePayment::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('billing:renew')->hourly()->onOneServer()->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RenewSubscriptionsCommand::class]);
        }
    }
}
