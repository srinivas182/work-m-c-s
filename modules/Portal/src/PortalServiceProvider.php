<?php

declare(strict_types=1);

namespace Wurk\Portal;

use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Portal\Support\DataRequests;

final class PortalServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->callAfterResolving(MessageTemplates::class, function (MessageTemplates $t): void { // Settings → Messages
            $t->define('portal.deletion_done', DataRequests::DONE);
            $t->define('portal.deletion_declined', DataRequests::DECLINED);
        });
    }

    public function menu(): array
    {
        return [['label' => 'Data requests', 'route' => 'tenant.data-requests', 'icon' => 'shield-check', 'section' => 'Customers', 'permission' => 'contacts.manage', 'order' => 360]];
    }
}
