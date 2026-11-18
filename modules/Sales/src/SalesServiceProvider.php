<?php

declare(strict_types=1);

namespace Wurk\Sales;

use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Platform\Contracts\OwnerDigest;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Sales\Digest\SalesDigest;
use Wurk\Sales\Models\Sale;
use Wurk\Sales\Support\Sales;

final class SalesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->callAfterResolving(MessageTemplates::class, fn (MessageTemplates $t) => $t->define('sale.receipt', Sales::RECEIPT)); // Settings → Messages
        $this->callAfterResolving(OwnerDigest::class, fn (OwnerDigest $d) => $d->register(SalesDigest::class)); // the owner's evening WhatsApp summary (21.6)
        // Who owes me (23.5): sales on account, not yet paid.
        $this->callAfterResolving(MoneyOwed::class, fn (MoneyOwed $o) => $o->register('sales', function (): array {
            $sales = Sale::query()->where('method', 'account')->where('status', 'recorded')->whereNull('settled_at')->orderBy('recorded_at')->limit(200)->get();
            $people = app(ContactBook::class)->names(array_values(array_filter($sales->map(fn (Sale $s) => $s->contact_id)->all(), 'is_string')));

            return $sales->map(fn (Sale $s) => ['key' => 'sale:'.$s->id, 'kind' => 'sale', 'title' => 'Sale on account', 'customer' => $people[$s->contact_id ?? ''] ?? 'Customer',
                'phone' => null, 'email' => null, 'amount_cents' => $s->amount_cents - $s->refunded_cents, 'since' => $s->recorded_at->toDateString(), 'due' => null,
                'link' => '/sales', 'settle' => '/sales/'.$s->id.'/settle'])->values()->all();
        }));
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
