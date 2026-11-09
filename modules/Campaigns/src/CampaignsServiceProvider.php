<?php

declare(strict_types=1);

namespace Wurk\Campaigns;

use Illuminate\Console\Scheduling\Schedule;
use Wurk\Campaigns\Console\SendDueCommand;
use Wurk\Campaigns\Support\CouponBook;
use Wurk\Payments\Contracts\Coupons;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class CampaignsServiceProvider extends ModuleServiceProvider
{
    public function menu(): array
    {
        return [
            ['label' => 'Campaigns', 'route' => 'tenant.campaigns', 'icon' => 'message-circle', 'section' => 'Customers', 'permission' => 'campaigns.manage', 'entitlement' => 'marketing', 'order' => 340],
            ['label' => 'Coupons', 'route' => 'tenant.coupons', 'icon' => 'tag', 'section' => 'Customers', 'permission' => 'campaigns.manage', 'entitlement' => 'marketing', 'order' => 345],
        ];
    }

    public function permissions(): array
    {
        return [['key' => 'campaigns.manage', 'label' => 'Send email and SMS campaigns to customers', 'scope' => 'business']];
    }

    public function register(): void
    {
        parent::register();
        $this->app->bind(Coupons::class, CouponBook::class); // the real coupon book (15.3b)
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
