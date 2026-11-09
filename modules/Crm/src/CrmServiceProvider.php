<?php

declare(strict_types=1);

namespace Wurk\Crm;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Wurk\Crm\Console\FollowUpsCommand;
use Wurk\Crm\Console\LinkExistingCommand;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\CustomerIdentity;
use Wurk\Crm\Contracts\LeadBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Contracts\PurchaseRefunded;
use Wurk\Crm\Reports\CustomersSection;
use Wurk\Crm\Support\Contacts;
use Wurk\Crm\Support\Leads;
use Wurk\Crm\Support\SignedCustomerIdentity;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;

final class CrmServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(CustomerIdentity::class, SignedCustomerIdentity::class);
        $this->app->bind(ContactBook::class, Contacts::class);
        $this->app->bind(LeadBook::class, Leads::class);
    }

    public function boot(): void
    {
        parent::boot();
        // Reports → Customers (Sprint 15.1a), with what each purchase was paid (for lifetime value).
        $this->callAfterResolving(BusinessReports::class, function (BusinessReports $r): void {
            $r->register('customers', 300, fn (ReportPeriod $p) => Gate::allows('reports.view')
                ? app(CustomersSection::class)->build($p) : null);
        });
        Event::listen(PurchaseMade::class, fn (PurchaseMade $e) => app(CustomersSection::class)->recordPurchase($e->sourceType, $e->sourceId, $e->paidCents));
        Event::listen(PurchaseRefunded::class, fn (PurchaseRefunded $e) => app(CustomersSection::class)->recordRefund($e->sourceType, $e->sourceId, $e->refundedCents));
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
        return [
            ['label' => 'Contacts', 'route' => 'tenant.contacts', 'icon' => 'users', 'section' => 'Customers', 'permission' => 'contacts.view', 'order' => 300],
            ['label' => 'Leads', 'route' => 'tenant.leads', 'icon' => 'columns', 'section' => 'Customers', 'permission' => 'leads.view', 'order' => 310],
        ];
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
