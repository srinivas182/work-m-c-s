<?php

declare(strict_types=1);

namespace Wurk\Sales;

use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Sales\Support\Sales;

final class SalesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->callAfterResolving(MessageTemplates::class, fn (MessageTemplates $t) => $t->define('sale.receipt', Sales::RECEIPT)); // Settings → Messages
    }

    public function menu(): array
    {
        return [['label' => 'Record sale', 'route' => 'tenant.sales', 'icon' => 'receipt', 'section' => 'Customers', 'permission' => 'sales.record', 'entitlement' => 'crm', 'order' => 305]];
    }

    public function permissions(): array
    {
        return [
            ['key' => 'sales.record', 'label' => 'Record in-store sales'],
            ['key' => 'sales.approve', 'label' => 'Approve, decline and refund in-store sales'],
            ['key' => 'sales.manage', 'label' => 'Set when in-store sales need approval'],
        ];
    }
}
