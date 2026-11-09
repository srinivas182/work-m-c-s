<?php

declare(strict_types=1);

namespace Wurk\Plans\Metrics;

use Carbon\CarbonImmutable;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Support\MrrSnapshots;
use Wurk\Platform\Support\ReportPeriod;

/** Admin → Metrics → Subscriptions (Sprint 15.4a): MRR, businesses, churn, LTV, trial conversion — by package and country. */
final readonly class SubscriptionsSection
{
    public function __construct(private MrrSnapshots $snapshots) {}

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        $now = $this->stats($p->from, $p->to);
        $before = $this->stats($p->previousFrom(), $p->previousTo());
        $names = Package::query()->pluck('name', 'id');
        $byPackage = collect($now['end'])->filter(fn ($r) => $r['mrr_cents'] > 0)->groupBy('package_id')
            ->map(fn ($g, $pkg) => [(string) ($names[$pkg] ?? 'Unknown'), (string) $g->count(), 'R'.number_format($g->sum('mrr_cents') / 100, 2)])->sortByDesc(fn ($r) => (int) $r[1])->values()->all();
        $byCountry = collect($now['end'])->filter(fn ($r) => $r['mrr_cents'] > 0)->groupBy('country')
            ->map(fn ($g, $c) => [(string) $c, (string) $g->count(), 'R'.number_format($g->sum('mrr_cents') / 100, 2)])->sortByDesc(fn ($r) => (int) $r[1])->values()->all();
        $since = $this->snapshots->since();
        $metric = fn (string $label, string $key, string $format) => ['label' => $label, 'value' => $now[$key], 'previous' => $before[$key], 'format' => $format];
        $highlights = [];
        if ($now['new'] > 0 || $now['lost'] > 0) {
            $highlights[] = "{$now['new']} new paying ".($now['new'] === 1 ? 'business' : 'businesses').", {$now['lost']} lost.";
        }
        if ($now['grace'] > 0) {
            $highlights[] = "{$now['grace']} ".($now['grace'] === 1 ? 'business is' : 'businesses are').' in their grace period after a failed payment.';
        }

        return [
            'title' => 'Subscriptions',
            'metrics' => [
                $metric('MRR (excl. VAT)', 'mrr', 'money'), $metric('ARR', 'arr', 'money'), $metric('Paying businesses', 'paying', 'count'),
                $metric('On trial', 'trial', 'count'), $metric('New paying', 'new', 'count'), $metric('Lost', 'lost', 'count'),
                $metric('In grace', 'grace', 'count'), $metric('Suspended', 'suspended', 'count'), $metric('Average revenue per business', 'arpu', 'money'),
                $metric('Churn (of paying at the start)', 'churn', 'percent'), $metric('Trial conversion', 'conversion', 'percent'),
                ...($now['ltv'] === null ? [] : [$metric('Lifetime value (estimate)', 'ltv', 'money')]),
            ],
            'chart' => null,
            'rows' => ['columns' => ['Package', 'Paying', 'MRR'], 'data' => $byPackage],
            'extra' => ['columns' => ['Country', 'Paying', 'MRR'], 'data' => $byCountry],
            'highlights' => $highlights,
            'note' => $since === null || $since > $p->previousFrom()->toDateString()
                ? 'History starts '.($since === null ? 'today' : 'on '.$since).': comparisons, new, lost and churn fill in from then.' : null,
        ];
    }

    /** @return array{end: list<array<string, mixed>>, mrr: int, arr: int, paying: int, trial: int, new: int, lost: int, grace: int, suspended: int, arpu: int, churn: float, conversion: float, ltv: int|null} */
    private function stats(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = collect($this->snapshots->on($from))->keyBy('tenant_id');
        $end = collect($this->snapshots->on($to))->keyBy('tenant_id');
        $paying = fn ($r) => $r !== null && in_array($r['status'], MrrSnapshots::PAYING, true);
        $payingStart = $start->filter($paying);
        $payingEnd = $end->filter($paying);
        $lost = $payingStart->keys()->filter(fn ($id) => ! $paying($end[$id] ?? null))->count();
        $trialStart = $start->filter(fn ($r) => $r['status'] === 'trial');
        $mrr = (int) $payingEnd->sum('mrr_cents');
        $arpu = $payingEnd->isEmpty() ? 0 : intdiv($mrr, $payingEnd->count());
        $churn = $payingStart->isEmpty() ? 0.0 : round($lost / $payingStart->count() * 100, 1);
        $months = max(1.0, $from->diffInDays($to) / 30);

        return ['end' => array_values($end->all()), 'mrr' => $mrr, 'arr' => $mrr * 12, 'paying' => $payingEnd->count(), 'trial' => $end->where('status', 'trial')->count(),
            'new' => $payingEnd->keys()->filter(fn ($id) => ! $paying($start[$id] ?? null))->count(), 'lost' => $lost,
            'grace' => $end->where('status', 'past_due')->count(), 'suspended' => $end->where('status', 'suspended')->count(), 'arpu' => $arpu, 'churn' => $churn,
            'conversion' => $trialStart->isEmpty() ? 0.0 : round($trialStart->keys()->filter(fn ($id) => $paying($end[$id] ?? null))->count() / $trialStart->count() * 100, 1),
            'ltv' => $churn > 0 ? (int) round($arpu / (($churn / 100) / $months)) : null]; // ARPU ÷ monthly churn
    }
}
