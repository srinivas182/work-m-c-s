<?php

declare(strict_types=1);

namespace Wurk\Billing\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Plans\Contracts\SubscriptionHistory;
use Wurk\Platform\Support\ReportPeriod;

/**
 * Admin → Metrics → Revenue (Sprint 15.4b): money WURK actually received (excl. VAT) — by purpose — less refunds
 * (credit notes) and chargebacks; VAT collected; failed payments. Goes back as far as payments do.
 */
final class RevenueSection
{
    public function __construct(private readonly SubscriptionHistory $subscriptions) {}

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
            'extra' => $this->byPackageAndCountry($p),
            'highlights' => $highlights,
        ];
    }

    /**
     * Net revenue (excl. VAT) by each business's package and country at the end of the period (Sprint 15.6).
     *
     * @return array{columns: list<string>, data: list<list<string>>}
     */
    private function byPackageAndCountry(ReportPeriod $p): array
    {
        $received = DB::table('payments')->whereIn('status', self::RECEIVED)->whereBetween('paid_at', [$p->from, $p->to])->selectRaw('tenant_id, sum(amount_excl_cents) as cents')->groupBy('tenant_id')->pluck('cents', 'tenant_id');
        $back = DB::table('payments')->where('status', 'charged_back')->whereBetween('paid_at', [$p->from, $p->to])->selectRaw('tenant_id, sum(amount_excl_cents) as cents')->groupBy('tenant_id')->pluck('cents', 'tenant_id');
        $notes = DB::table('credit_notes')->whereBetween('issued_at', [$p->from, $p->to])->selectRaw('tenant_id, sum(excl_cents) as cents')->groupBy('tenant_id')->pluck('cents', 'tenant_id');
        $at = collect($this->subscriptions->on($p->to))->keyBy('tenant_id');
        $names = $this->subscriptions->packageNames();
        $groups = [];
        foreach ($received->keys()->merge($notes->keys())->unique() as $id) {
            $s = $at[$id] ?? null;
            $key = ($names[(string) ($s['package_id'] ?? '')] ?? 'No package').'|'.($s['country'] ?? '—');
            $groups[$key] = ($groups[$key] ?? 0) + (int) ($received[$id] ?? 0) - (int) ($back[$id] ?? 0) - (int) ($notes[$id] ?? 0);
        }
        arsort($groups);

        return ['columns' => ['Package', 'Country', 'Net revenue (excl. VAT)'], 'data' => array_values(array_map(fn ($k, $c) => [...explode('|', (string) $k), ($c < 0 ? '−' : '').'R'.number_format(abs($c) / 100, 2)], array_keys($groups), array_values($groups)))];
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
