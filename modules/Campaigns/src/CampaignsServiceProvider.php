<?php

declare(strict_types=1);

namespace Wurk\Campaigns;

use Illuminate\Console\Scheduling\Schedule;
use Wurk\Campaigns\Console\SendDueCommand;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class CampaignsServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [['label' => 'Campaigns', 'route' => 'tenant.campaigns', 'icon' => 'message-circle', 'section' => 'Customers', 'permission' => 'campaigns.manage', 'entitlement' => 'marketing', 'order' => 340]];
    }

    public function permissions(): array
    {
        return [['key' => 'campaigns.manage', 'label' => 'Send email and SMS campaigns to customers', 'scope' => 'business']];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'campaigns');
        if ($this->app->runningInConsole()) {
            $this->commands([SendDueCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('campaigns:send-due')->everyFiveMinutes()->onOneServer()->withoutOverlapping());
    }
}
