<?php

declare(strict_types=1);

namespace Wurk\Crm;

use Wurk\Crm\Console\LinkExistingCommand;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Support\Contacts;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class CrmServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->bind(ContactBook::class, Contacts::class);
    }

    public function boot(): void
    {
        parent::boot();
        if ($this->app->runningInConsole()) {
            $this->commands([LinkExistingCommand::class]);
        }
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
        ];
    }
}
