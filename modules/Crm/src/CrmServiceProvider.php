<?php

declare(strict_types=1);

namespace Wurk\Crm;

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

    public function permissions(): array
    {
        return [
            ['key' => 'contacts.view', 'label' => 'See contacts'],
            ['key' => 'contacts.manage', 'label' => 'Edit, tag, merge and export contacts'],
        ];
    }
}
