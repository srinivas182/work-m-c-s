<?php

declare(strict_types=1);

namespace Wurk\Billing\Metrics;

use Illuminate\Support\Facades\DB;
use Wurk\Credits\Contracts\UsageHistory;
use Wurk\Plans\Contracts\SubscriptionHistory;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Admin → Metrics → Profit (Sprint 15.5a). Per business: what it paid (excl. VAT, less refunds and chargebacks) − what
 * its messages cost WURK (provider cost per 1,000 × messages) − WURK's running cost per business per month. By package;
 * margin alerts for businesses whose messages cost more than they paid. Costs not set are said, not guessed.
 */
final readonly class ProfitSection
{
    private const RECEIVED = ['succeeded', 'refunded', 'charged_back'];

    public function __construct(private Costs $costs, private SubscriptionHistory $subscriptions, private UsageHistory $usage, private TenantDirectory $tenants) {}

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        $months = max(1 / 31, $p->from->diffInDays($p->to) / 30.4375);
        $end = collect($this->subscriptions->on($p->to))->keyBy('tenant_id');
        $paid = DB::table('payments')->whereIn('status', self::RECEIVED)->whereBetween('paid_at', [$p->from, $p->to])->selectRaw('tenant_id, sum(amount_excl_cents) as cents')->groupBy('tenant_id')->pluck('cents', 'tenant_id');
        $back = DB::table('payments')->where('status', 'charged_back')->whereBetween('paid_at', [$p->from, $p->to])->selectRaw('tenant_id, sum(amount_excl_cents) as cents')->groupBy('tenant_id')->pluck('cents', 'tenant_id');
        $notes = DB::table('credit_notes')->whereBetween('issued_at', [$p->from, $p->to])->selectRaw('tenant_id, sum(excl_cents) as cents')->groupBy('tenant_id')->pluck('cents', 'tenant_id');
        $used = $this->usage->between($p->from, $p->to);
        $names = $this->subscriptions->packageNames();
        $missingProvider = [];
        $runningUnset = false;
        $rows = [];
        foreach (collect($end->keys())->merge($paid->keys())->merge(array_keys($used))->unique() as $id) {
            $s = $end[$id] ?? null;
            $country = (string) ($s['country'] ?? 'ZA');
            $revenue = (int) ($paid[$id] ?? 0) - (int) ($back[$id] ?? 0) - (int) ($notes[$id] ?? 0);
            $provider = 0;
            foreach ($used[$id] ?? [] as $channel => $u) {
                $per1000 = $this->costs->providerPer1000((string) $channel, $country);
                if ($per1000 === null) {
                    $missingProvider[$channel] = true;

                    continue;
                }
                $provider += intdiv($u['messages'] * $per1000, 1000);
            }
            $isCustomer = $s !== null && in_array($s['status'], ['trial', 'active', 'past_due'], true); // trials cost WURK too
            $runningPerMonth = $isCustomer ? $this->costs->runningPerMonth($s['package_id']) : 0;
            $runningUnset = $runningUnset || ($isCustomer && $runningPerMonth === null);
            $running = (int) round(($runningPerMonth ?? 0) * $months);
            $rows[] = ['tenant_id' => (string) $id, 'package' => $names[(string) ($s['package_id'] ?? '')] ?? 'No package', 'revenue' => $revenue, 'provider' => $provider, 'running' => $running];
        }
        $byPackage = collect($rows)->groupBy('package')->map(function ($g, $package) {
            [$rev, $prov, $run] = [(int) $g->sum('revenue'), (int) $g->sum('provider'), (int) $g->sum('running')];

            return [(string) $package, (string) $g->count(), self::r($rev), self::r($prov), self::r($run), self::r($rev - $prov - $run), $rev > 0 ? round(($rev - $prov - $run) / $rev * 100).'%' : '—'];
        })->sortBy(0)->values()->all();
        $alerts = collect($rows)->filter(fn ($r) => $r['provider'] > $r['revenue'])->sortByDesc(fn ($r) => $r['provider'] - $r['revenue'])->take(10)
            ->map(fn ($r) => [$this->tenants->find($r['tenant_id'])?->displayName() ?? 'A business', $r['package'], self::r($r['revenue']), self::r($r['provider'])])->values()->all();
        [$rev, $prov, $run] = [(int) collect($rows)->sum('revenue'), (int) collect($rows)->sum('provider'), (int) collect($rows)->sum('running')];
        $notes = [];
        if ($missingProvider !== []) {
            $notes[] = 'Provider cost not set for '.implode(', ', array_keys($missingProvider)).' — left out of costs.';
        }
        if ($runningUnset) {
            $notes[] = 'Running cost per business isn’t set — counted as R0.';
        }

        return [
            'title' => 'Profit',
            'metrics' => [
                ['label' => 'Revenue (excl. VAT)', 'value' => $rev, 'previous' => 0, 'format' => 'money', 'snapshot' => true],
                ['label' => 'Provider costs', 'value' => $prov, 'previous' => 0, 'format' => 'money', 'snapshot' => true],
                ['label' => 'Running costs', 'value' => $run, 'previous' => 0, 'format' => 'money', 'snapshot' => true],
                ['label' => 'Profit', 'value' => $rev - $prov - $run, 'previous' => 0, 'format' => 'money', 'snapshot' => true],
            ],
            'chart' => null,
            'rows' => ['columns' => ['Package', 'Businesses', 'Revenue', 'Provider costs', 'Running costs', 'Profit', 'Margin'], 'data' => $byPackage],
            'extra' => ['columns' => ['Margin alert: messages cost more than paid', 'Package', 'Paid', 'Message costs'], 'data' => $alerts],
            'highlights' => $alerts === [] ? [] : [count($alerts).' '.(count($alerts) === 1 ? 'business’s' : 'businesses’').' messages cost WURK more than they paid.'],
            'note' => $notes === [] ? 'For this period. Set costs in Admin → Profitability.' : implode(' ', $notes).' Set costs in Admin → Profitability.',
        ];
    }

    private static function r(int $cents): string
    {
        return ($cents < 0 ? '−' : '').'R'.number_format(abs($cents) / 100, 2);
    }
}
