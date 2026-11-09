<?php

declare(strict_types=1);

namespace Wurk\Shop\Reports;

use Illuminate\Support\Facades\DB;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

/** Reports → Stock (Sprint 15.1b): out of stock, lowest stock, and stocked items that didn't sell in the period. */
final readonly class StockSection
{
    public function __construct(private TenantContext $context) {}

    /** @return array<string, mixed>|null null when the shop tracks no stock */
    public function build(ReportPeriod $p): ?array
    {
        $db = DB::connection($this->context->connectionName());
        $t = $this->context->id();
        $stock = $db->table('shop_stock')->join('shop_variants', 'shop_variants.id', '=', 'shop_stock.variant_id')->join('shop_products', 'shop_products.id', '=', 'shop_variants.product_id')
            ->where('shop_stock.tenant_id', $t)->where('shop_variants.tenant_id', $t)->where('shop_products.tenant_id', $t)
            ->when($p->branchIds !== null, fn ($q) => $q->whereIn('shop_stock.branch_id', $p->branchIds))
            ->selectRaw('shop_variants.id as id, shop_products.name as product, shop_variants.label as label, sum(shop_stock.quantity) as qty')
            ->groupBy('shop_variants.id', 'shop_products.name', 'shop_variants.label')->get();
        if ($stock->isEmpty()) {
            return null;
        }
        $sold = $db->table('shop_order_items')->join('shop_orders', 'shop_orders.id', '=', 'shop_order_items.order_id')->where('shop_order_items.tenant_id', $t)->where('shop_orders.tenant_id', $t)
            ->whereNotNull('shop_orders.paid_at')->whereBetween('shop_orders.paid_at', [$p->from, $p->to])
            ->when($p->branchIds !== null, fn ($q) => $q->whereIn('shop_orders.branch_id', $p->branchIds))->distinct()->pluck('shop_order_items.variant_id')->all();
        $name = fn ($v) => $v->product.($v->label ? ' — '.$v->label : '');
        $out = $stock->filter(fn ($v) => (int) $v->qty <= 0);
        $slow = $stock->filter(fn ($v) => (int) $v->qty > 0 && ! in_array($v->id, $sold, true));
        $highlights = [];
        if ($out->isNotEmpty()) {
            $highlights[] = $out->count().' '.($out->count() === 1 ? 'item is' : 'items are').' out of stock.';
        }

        return [
            'title' => 'Stock',
            'metrics' => [
                ['label' => 'Out of stock', 'value' => $out->count(), 'previous' => 0, 'format' => 'count', 'snapshot' => true],
                ['label' => 'Didn’t sell this period', 'value' => $slow->count(), 'previous' => 0, 'format' => 'count', 'snapshot' => true],
                ['label' => 'Items in stock', 'value' => (int) $stock->sum(fn ($v) => max(0, (int) $v->qty)), 'previous' => 0, 'format' => 'count', 'snapshot' => true],
            ],
            'chart' => null,
            'rows' => ['columns' => ['Lowest stock', 'Left'], 'data' => array_values($stock->sortBy(fn ($v) => (int) $v->qty)->take(5)->map(fn ($v) => [$name($v), (string) (int) $v->qty])->all())],
            'extra' => ['columns' => ['Didn’t sell this period', 'In stock'], 'data' => array_values($slow->sortByDesc(fn ($v) => (int) $v->qty)->take(5)->map(fn ($v) => [$name($v), (string) (int) $v->qty])->all())],
            'highlights' => $highlights,
        ];
    }
}
