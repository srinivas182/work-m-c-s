<?php

declare(strict_types=1);

namespace Wurk\Agents\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Platform\Support\ReportPeriod;

/** Admin → Metrics → Agent commission (Sprint 15.4b): earned, reversed and paid out in the period; owed now. */
final class CommissionSection
{
    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        [$now, $before] = [$this->stats($p->from, $p->to), $this->stats($p->previousFrom(), $p->previousTo())];
        $metric = fn (string $label, string $key) => ['label' => $label, 'value' => $now[$key], 'previous' => $before[$key], 'format' => 'money'];
        $balance = DB::table('agent_ledger')->selectRaw('coalesce(sum(case when available_at <= ? then cents else 0 end), 0) as payable, coalesce(sum(case when available_at > ? then cents else 0 end), 0) as pending', [now(), now()])->first();

        return [
            'title' => 'Agent commission',
            'metrics' => [$metric('Earned', 'earned'), $metric('Reversed', 'reversed'), $metric('Paid out', 'paid'),
                ['label' => 'Payable now', 'value' => max(0, (int) ($balance->payable ?? 0)), 'previous' => 0, 'format' => 'money', 'snapshot' => true],
                ['label' => 'Pending (refund window)', 'value' => max(0, (int) ($balance->pending ?? 0)), 'previous' => 0, 'format' => 'money', 'snapshot' => true]],
            'chart' => null, 'rows' => null, 'extra' => null, 'highlights' => [],
        ];
    }

    /** @return array{earned: int, reversed: int, paid: int} */
    private function stats(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $by = DB::table('agent_ledger')->whereBetween('created_at', [$from, $to])->selectRaw('kind, sum(cents) as cents')->groupBy('kind')->pluck('cents', 'kind');

        return ['earned' => (int) ($by['earned'] ?? 0), 'reversed' => abs((int) ($by['reversed'] ?? 0)), 'paid' => abs((int) ($by['paid_out'] ?? 0))];
    }
}
