<?php

declare(strict_types=1);

namespace Wurk\Portal;

use Illuminate\Support\Facades\Event;
use Wurk\Crm\Contracts\ContactRemembered;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Portal\Support\DataRequests;
use Wurk\Portal\Support\DefaultPasswords;

final class PortalServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->callAfterResolving(MessageTemplates::class, function (MessageTemplates $t): void { // Settings → Messages
            $t->define('portal.deletion_done', DataRequests::DONE);
            $t->define('portal.deletion_declined', DataRequests::DECLINED);
            $t->define('portal.password', DefaultPasswords::TEMPLATE);
        });
    }

    public function boot(): void
    {
        parent::boot();
        // A new customer with an email at a business that allows passwords gets a default password (decision J).
        Event::listen(ContactRemembered::class, function (ContactRemembered $e): void {
            if ($e->created && $e->sourceType !== 'portal' && app(DefaultPasswords::class)->allowed()) {
                app(DefaultPasswords::class)->issue($e->contactId, 'email');
            }
        });
    }

    public function menu(): array
    {
        return [
            ['label' => 'Data requests', 'route' => 'tenant.data-requests', 'icon' => 'shield-check', 'section' => 'Customers', 'permission' => 'contacts.manage', 'order' => 360],
            ['label' => 'Customer accounts', 'route' => 'tenant.customer-accounts', 'icon' => 'key-round', 'section' => 'Settings', 'permission' => 'contacts.manage', 'order' => 836],
        ];
    }
}
