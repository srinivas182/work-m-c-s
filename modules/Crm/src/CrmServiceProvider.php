<?php

declare(strict_types=1);

namespace Wurk\Crm;

use Illuminate\Console\Scheduling\Schedule;
use Wurk\Crm\Console\FollowUpsCommand;
use Wurk\Crm\Console\LinkExistingCommand;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\LeadBook;
use Wurk\Crm\Support\Contacts;
use Wurk\Crm\Support\Leads;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class CrmServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(ContactBook::class, Contacts::class);
        $this->app->bind(LeadBook::class, Leads::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadViewsFrom($this->modulePath('resources/views'), 'crm');
        if ($this->app->runningInConsole()) {
            $this->commands([LinkExistingCommand::class, FollowUpsCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('crm:follow-ups')->dailyAt('05:00')->onOneServer()->withoutOverlapping(); // 07:00 in South Africa
        });
    }

    public function menu(): array
    {
        return [['label' => 'Contacts', 'route' => 'tenant.contacts', 'icon' => 'users', 'section' => 'Customers', 'permission' => 'contacts.view', 'order' => 300]];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'contacts.view', 'label' => 'See contacts'],
            ['key' => 'contacts.manage', 'label' => 'Edit, tag, merge and export contacts'],
            ['key' => 'leads.view', 'label' => 'See leads and pipelines'],
            ['key' => 'leads.manage', 'label' => 'Add and move leads; change pipeline stages'],
        ];
    }
}
