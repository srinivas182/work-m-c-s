<?php

declare(strict_types=1);

namespace Wurk\Billing\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Platform\Support\ReportPeriod;

/**
 * Admin → Metrics → Revenue (Sprint 15.4b): money WURK actually received (excl. VAT) — by purpose — less refunds
 * (credit notes) and chargebacks; VAT collected; failed payments. Goes back as far as payments do.
 */
final class RevenueSection
{
    private const RECEIVED = ['succeeded', 'refunded', 'charged_back']; // it arrived, even if it went back later

    private const PURPOSES = ['subscription' => 'Subscriptions', 'upgrade' => 'Upgrades', 'credits' => 'Credits'];

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        [$now, $before] = [$this->stats($p->from, $p->to), $this->stats($p->previousFrom(), $p->previousTo())];
        $metric = fn (string $label, string $key, string $format = 'money') => ['label' => $label, 'value' => $now[$key], 'previous' => $before[$key], 'format' => $format];
        $highlights = [];
        if (($c = ReportPeriod::change($now['net'], $before['net'])) !== null) {
            $highlights[] = 'Net revenue was '.($c >= 0 ? 'up' : 'down').' '.abs($c).'% on the previous period.';
        }

        return [
            'title' => 'Revenue',
            'metrics' => [$metric('Received (excl. VAT)', 'received'), $metric('Refunds', 'refunds'), $metric('Chargebacks', 'chargebacks'), $metric('Net revenue', 'net'),
                $metric('VAT collected', 'vat'), $metric('Failed payments', 'failed', 'count')],
            'chart' => null,
            'rows' => ['columns' => ['From', 'Payments', 'Received (excl. VAT)'], 'data' => $now['purposes']],
            'extra' => null,
            'highlights' => $highlights,
        ];
    }

    /** @return array{received: int, refunds: int, chargebacks: int, net: int, vat: int, failed: int, purposes: list<list<string>>} */
    private function stats(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $received = DB::table('payments')->whereIn('status', self::RECEIVED)->whereBetween('paid_at', [$from, $to])->get(['purpose', 'status', 'amount_excl_cents', 'vat_cents']);
        $refunds = (int) DB::table('credit_notes')->whereBetween('issued_at', [$from, $to])->sum('excl_cents');
        $chargebacks = (int) $received->where('status', 'charged_back')->sum('amount_excl_cents');
        $in = (int) $received->sum('amount_excl_cents');
        $purposes = [];
        foreach ($received->groupBy('purpose') as $purpose => $g) {
            $purposes[] = [self::PURPOSES[$purpose] ?? ucfirst((string) $purpose), (string) $g->count(), 'R'.number_format($g->sum('amount_excl_cents') / 100, 2)];
        }

        return ['received' => $in, 'refunds' => $refunds, 'chargebacks' => $chargebacks, 'net' => $in - $refunds - $chargebacks, 'vat' => (int) $received->sum('vat_cents'),
            'failed' => DB::table('payments')->where('status', 'failed')->whereBetween('created_at', [$from, $to])->count(), 'purposes' => $purposes];
    }
}
