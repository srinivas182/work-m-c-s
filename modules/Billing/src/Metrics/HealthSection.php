<?php

declare(strict_types=1);

namespace Wurk\Billing\Metrics;

use Carbon\CarbonImmutable;
use Wurk\Identity\Contracts\TeamActivity;
use Wurk\Plans\Contracts\SubscriptionHistory;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Admin → Metrics → At risk (Sprint 15.6): paying businesses where no one has signed in for 30+ days — a churn signal. */
final readonly class HealthSection
{
    public const DAYS = 30;

    public function __construct(private SubscriptionHistory $subscriptions, private TeamActivity $activity, private TenantDirectory $tenants) {}

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        $paying = collect($this->subscriptions->on($p->to))->filter(fn ($r) => $r['mrr_cents'] > 0)->keyBy('tenant_id');
        $seen = $this->activity->lastSignIn(array_values(array_map('strval', $paying->keys()->all())));
        $cutoff = $p->to->subDays(self::DAYS);
        $names = $this->subscriptions->packageNames();
        $risk = $paying->filter(fn ($r, $id) => ($seen[$id] ?? null) === null || CarbonImmutable::parse((string) $seen[$id])->lt($cutoff))->sortByDesc('mrr_cents');
        $mrr = (int) $risk->sum('mrr_cents');

        return [
            'title' => 'At risk',
            'metrics' => [
                ['label' => 'Paying businesses not signed in for '.self::DAYS.'+ days', 'value' => $risk->count(), 'previous' => 0, 'format' => 'count', 'snapshot' => true],
                ['label' => 'Their MRR', 'value' => $mrr, 'previous' => 0, 'format' => 'money', 'snapshot' => true],
            ],
            'chart' => null,
            'rows' => ['columns' => ['Business', 'Package', 'MRR', 'Last sign-in'], 'data' => array_values($risk->take(20)->map(fn ($r, $id) => [
                $this->tenants->find((string) $id)?->displayName() ?? 'A business', $names[(string) $r['package_id']] ?? '—', 'R'.number_format($r['mrr_cents'] / 100, 2),
                ($seen[$id] ?? null) === null ? 'Never' : CarbonImmutable::parse((string) $seen[$id])->setTimezone('Africa/Johannesburg')->toDateString(),
            ])->all())],
            'extra' => null,
            'highlights' => $risk->isEmpty() ? [] : [$risk->count().' paying '.($risk->count() === 1 ? 'business hasn’t' : 'businesses haven’t').' signed in for '.self::DAYS.'+ days ('.'R'.number_format($mrr / 100, 2).' MRR) — worth a call.'],
            'note' => 'As at the end of the period; the 20 with the most MRR are listed.',
        ];
    }
}
