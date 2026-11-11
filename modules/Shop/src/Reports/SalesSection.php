<?php

declare(strict_types=1);

namespace Wurk\Shop\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

/** Reports → Sales (Sprint 15.1a): the shop's paid orders, net of refunds, for the period and the one before. */
final readonly class SalesSection
{
    public function __construct(private TenantContext $context) {}

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        $now = $this->orders($p, $p->from, $p->to);
        $before = $this->orders($p, $p->previousFrom(), $p->previousTo());
        $net = fn ($rows) => (int) $rows->sum(fn ($o) => (int) $o->total_cents - (int) $o->refunded_cents);
        [$sales, $prevSales] = [$net($now), $net($before)];
        $count = $now->count();
        $tz = $this->context->current()->timezone();
        $days = [];
        foreach ($now as $o) {
            $d = CarbonImmutable::parse((string) $o->paid_at)->setTimezone($tz)->format('j M');
            $days[$d] = ($days[$d] ?? 0) + (int) $o->total_cents - (int) $o->refunded_cents;
        }
        $methods = $now->groupBy('payment_method')->map(fn ($g) => [(int) $g->count(), (int) $g->sum(fn ($o) => (int) $o->total_cents - (int) $o->refunded_cents)]);
        $best = $now->isEmpty() ? collect() : DB::connection($this->context->connectionName())->table('shop_order_items')->where('tenant_id', $this->context->id())
            ->whereIn('order_id', $now->pluck('id'))->selectRaw('product_name, sum(quantity) as qty, sum(line_cents) as cents')->groupBy('product_name')->orderByDesc('cents')->limit(5)->get();
        $highlights = [];
        if (($change = ReportPeriod::change($sales, $prevSales)) !== null) {
            $highlights[] = 'Shop sales were '.($change >= 0 ? 'up' : 'down').' '.abs($change).'% on the previous period.';
        }
        if ($best->isNotEmpty()) {
            $highlights[] = 'Your best seller was '.$best->first()->product_name.' ('.(int) $best->first()->qty.' sold).';
        }

        return [
            'title' => 'Shop sales',
            'metrics' => [
                ['label' => 'Sales (after refunds)', 'value' => $sales, 'previous' => $prevSales, 'format' => 'money'],
                ['label' => 'Orders', 'value' => $count, 'previous' => $before->count(), 'format' => 'count'],
                ['label' => 'Average order', 'value' => $count > 0 ? intdiv($sales, $count) : 0, 'previous' => $before->count() > 0 ? intdiv($prevSales, $before->count()) : 0, 'format' => 'money'],
            ],
            'chart' => ['label' => 'Sales by day', 'points' => array_map(fn ($d, $c) => ['label' => $d, 'value' => $c], array_keys($days), array_values($days))],
            'rows' => ['columns' => ['Best sellers', 'Sold', 'Sales'], 'data' => array_values($best->map(fn ($b) => [(string) $b->product_name, (string) (int) $b->qty, 'R'.number_format(((int) $b->cents) / 100, 2)])->all())],
            'extra' => ['columns' => ['Payment method', 'Orders', 'Sales'], 'data' => array_values($methods->map(fn ($v, $k) => [ucfirst((string) $k), (string) $v[0], 'R'.number_format($v[1] / 100, 2)])->all())],
            'highlights' => $highlights,
        ];
    }

    /** @return Collection<int, \stdClass> */
    private function orders(ReportPeriod $p, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::connection($this->context->connectionName())->table('shop_orders')->where('tenant_id', $this->context->id())
            ->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to])
            ->when($p->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $p->branchIds))
            ->get(['id', 'total_cents', 'refunded_cents', 'payment_method', 'paid_at']);
    }
}
