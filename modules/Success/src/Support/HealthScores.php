<?php

declare(strict_types=1);

namespace Wurk\Success\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Platform\Contracts\HealthSignals;
use Wurk\Success\Models\BusinessHealth;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Works out every business's health (Sprint 16.5): 100, less each module's signals; healthy 80+, watch 50–79, at risk
 * below 50. MRR comes from the subscription (yearly ÷ 12; trials count as nothing yet). Demo businesses are skipped.
 */
final readonly class HealthScores
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private HealthSignals $signals, private Catalog $catalog) {}

    public function scoreAll(): int
    {
        $done = 0;
        $active = $this->tenants->activeIds();
        foreach ($active as $id) {
            $done += (int) $this->score($id);
        }
        BusinessHealth::query()->whereNotIn('tenant_id', $active)->delete(); // closed or suspended businesses drop off

        return $done;
    }

    public function score(string $tenantId): bool
    {
        $tenant = $this->tenants->find($tenantId);
        if ($tenant === null || $tenant->isDemo()) {
            return false;
        }
        $signals = $this->context->run($tenant, fn () => $this->signals->collect($tenantId));
        $score = max(0, 100 - array_sum(array_column($signals, 'points')));
        $grade = BusinessHealth::gradeFor($score);
        $sub = $this->catalog->subscriptionOf($tenantId);
        $mrr = $sub === null || ($sub['trial_ends_at'] !== null && CarbonImmutable::parse($sub['trial_ends_at'])->isFuture()) ? 0
            : (int) ($sub['period'] === 'yearly' ? intdiv($sub['period_cents'], 12) : $sub['period_cents']);
        $before = BusinessHealth::query()->find($tenantId);
        DB::transaction(fn () => BusinessHealth::query()->updateOrCreate(['tenant_id' => $tenantId], [
            'name' => $tenant->displayName(), 'package_key' => $sub['package_key'] ?? null, 'score' => $score, 'previous_score' => $before?->score,
            'grade' => $grade, 'signals' => $signals, 'mrr_cents' => $mrr, 'computed_at' => now(),
            'at_risk_since' => $grade === 'at_risk' ? ($before->at_risk_since ?? now()) : null,
        ]));

        return true;
    }

    /** Count a server error for the business it happened in (kept 90 days). */
    public static function countError(string $tenantId): void
    {
        $day = now()->toDateString();
        if (DB::table('business_errors_daily')->where('tenant_id', $tenantId)->where('day', $day)->increment('count') === 0) {
            DB::table('business_errors_daily')->insertOrIgnore(['tenant_id' => $tenantId, 'day' => $day, 'count' => 1]);
        }
    }
}
