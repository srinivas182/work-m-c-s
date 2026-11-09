<?php

declare(strict_types=1);

namespace Wurk\Plans\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Plans\Support\MrrSnapshots;
use Wurk\Platform\Support\ReportPeriod;

/**
 * Admin → Metrics → MRR movement and cohorts (Sprint 15.4b), from the daily snapshots: how MRR moved between the start
 * and end of the period (it always adds up), and of each month's new paying businesses, how many are still paying.
 */
final readonly class MovementSection
{
    public function __construct(private MrrSnapshots $snapshots) {}

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        $m = $this->movement($p->from, $p->to);
        $line = fn (string $label, int $cents) => [$label, ($cents < 0 ? '−' : '').'R'.number_format(abs($cents) / 100, 2)];

        return [
            'title' => 'MRR movement',
            'metrics' => [
                ['label' => 'Opening MRR', 'value' => $m['opening'], 'previous' => 0, 'format' => 'money', 'snapshot' => true],
                ['label' => 'Closing MRR', 'value' => $m['closing'], 'previous' => $m['opening'], 'format' => 'money'],
                ['label' => 'Net new MRR', 'value' => $m['closing'] - $m['opening'], 'previous' => 0, 'format' => 'money', 'snapshot' => true],
            ],
            'chart' => null,
            'rows' => ['columns' => ['Movement', 'MRR'], 'data' => [$line('Opening', $m['opening']), $line('+ New', $m['new']), $line('+ Expansion', $m['expansion']),
                $line('+ Reactivation', $m['reactivation']), $line('− Contraction', -$m['contraction']), $line('− Churn', -$m['churn']), $line('Closing', $m['closing'])]],
            'extra' => $this->cohorts(),
            'highlights' => [],
            'note' => 'Below: of the businesses that started paying each month, the share still paying at each month-end since (from the daily snapshots).',
        ];
    }

    /** @return array{opening: int, closing: int, new: int, expansion: int, reactivation: int, contraction: int, churn: int} */
    public function movement(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = collect($this->snapshots->on($from))->keyBy('tenant_id');
        $end = collect($this->snapshots->on($to))->keyBy('tenant_id');
        $paidBefore = DB::table('subscription_snapshots')->where('day', '<', $from->toDateString())->where('mrr_cents', '>', 0)->distinct()->pluck('tenant_id')->flip();
        $m = ['opening' => (int) $start->sum('mrr_cents'), 'closing' => (int) $end->sum('mrr_cents'), 'new' => 0, 'expansion' => 0, 'reactivation' => 0, 'contraction' => 0, 'churn' => 0];
        foreach ($start->keys()->merge($end->keys())->unique() as $id) {
            $s = (int) ($start[$id]['mrr_cents'] ?? 0);
            $e = (int) ($end[$id]['mrr_cents'] ?? 0);
            match (true) {
                $s === 0 && $e > 0 => isset($paidBefore[$id]) ? $m['reactivation'] += $e : $m['new'] += $e,
                $s > 0 && $e === 0 => $m['churn'] += $s,
                $e > $s => $m['expansion'] += $e - $s,
                $e < $s => $m['contraction'] += $s - $e,
                default => null,
            };
        }

        return $m;
    }

    /** @return array{columns: list<string>, data: list<list<string>>} the last six months' cohorts */
    public function cohorts(): array
    {
        $first = DB::table('subscription_snapshots')->where('mrr_cents', '>', 0)->selectRaw('tenant_id, min(day) as first_day')->groupBy('tenant_id')->get()
            ->groupBy(fn ($r) => substr((string) $r->first_day, 0, 7));
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow($i);
        }
        $data = [];
        foreach ($months as $month) {
            $ids = collect($first[$month->format('Y-m')] ?? [])->pluck('tenant_id')->flip();
            if ($ids->isEmpty()) {
                continue;
            }
            $row = [$month->format('M Y'), (string) $ids->count()];
            foreach ($months as $later) {
                if ($later->lt($month)) {
                    continue;
                }
                $at = $later->endOfMonth()->min(CarbonImmutable::now());
                $paying = collect($this->snapshots->on($at))->filter(fn ($r) => isset($ids[$r['tenant_id']]) && $r['mrr_cents'] > 0)->count();
                $row[] = round($paying / $ids->count() * 100).'%';
            }
            $data[] = $row;
        }

        return ['columns' => ['Started paying', 'Businesses', 'Month 0', 'Month 1', 'Month 2', 'Month 3', 'Month 4', 'Month 5'], 'data' => $data];
    }
}
