<?php

declare(strict_types=1);

namespace Wurk\Trust;

use Illuminate\Console\Scheduling\Schedule;
use Wurk\Platform\Contracts\BusinessBadge;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Trust\Console\PurgeDocumentsCommand;
use Wurk\Trust\Support\Verifications;

/**
 * Trust (Sprint 25.4): the verified business badge — the owner's phone, their identity checked by WURK HQ, and a
 * registry match for companies. Documents are encrypted and deleted 90 days after the decision.
 */
final class TrustServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BusinessBadge::class, Verifications::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadViewsFrom($this->modulePath('resources/views'), 'trust');
        $this->callAfterResolving(Schedule::class, function (Schedule $s): void {
            $s->command('trust:purge-documents')->dailyAt('03:10')->timezone('Africa/Johannesburg')->onOneServer()->withoutOverlapping();
        });
        if ($this->app->runningInConsole()) {
            $this->commands([PurgeDocumentsCommand::class]);
        }
    }

    public function permissions(): array
    {
        return [
            ['key' => 'trust.manage', 'label' => 'Verified business: send the owner’s ID and the business’s registration'],
            ['key' => 'verification.review', 'label' => 'Check businesses’ ID documents and registrations', 'scope' => 'platform'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Verified business', 'route' => 'tenant.verified', 'icon' => 'shield-check', 'section' => 'Settings', 'permission' => 'trust.manage', 'order' => 836],
            ['label' => 'Verification', 'route' => 'admin.verification', 'icon' => 'shield-check', 'section' => 'Platform', 'permission' => 'verification.review', 'order' => 25],
        ];
    }
}
