<?php

declare(strict_types=1);

namespace Wurk\Campaigns;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Wurk\Campaigns\Assistant\CreateCouponTool;
use Wurk\Campaigns\Assistant\DraftCampaignTool;
use Wurk\Campaigns\Console\SendDueCommand;
use Wurk\Campaigns\Support\CouponBook;
use Wurk\Payments\Contracts\Coupons;
use Wurk\Platform\Contracts\AssistantTools;
use Wurk\Platform\Contracts\DataExports;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Contracts\TenantContext;

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
        // Your data (16.6): what this module keeps, for the full export.
        $this->callAfterResolving(DataExports::class, function (DataExports $x): void {
            $rows = fn (string $table, string $order) => fn () => DB::connection(app(TenantContext::class)->connectionName())->table($table)
                ->where('tenant_id', app(TenantContext::class)->id())->orderBy($order)->lazy(500)->map(fn ($r) => (array) $r);
            $x->table('campaigns', 'Campaigns', 'campaigns.manage', $rows('campaigns', 'created_at'));
            $x->table('coupons', 'Coupons', 'campaigns.manage', $rows('coupons', 'created_at'));
        });
        $this->loadViewsFrom($this->modulePath('resources/views'), 'campaigns');
        if ($this->app->runningInConsole()) {
            $this->commands([SendDueCommand::class]);
        }
        $this->callAfterResolving(AssistantTools::class, function (AssistantTools $t): void {
            $t->register(CreateCouponTool::class);
            $t->register(DraftCampaignTool::class);
        });
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('campaigns:send-due')->everyFiveMinutes()->onOneServer()->withoutOverlapping());
    }
}
