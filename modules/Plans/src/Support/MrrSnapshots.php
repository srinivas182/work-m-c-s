<?php

declare(strict_types=1);

namespace Wurk\Plans\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Plans\Models\PackageVersion;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Each business's subscription as it stood each day (Sprint 15.4). MRR is the package price excl. VAT — monthly, or a
 * twelfth of yearly — for businesses that are paying (active, or past due within grace); trials and suspended are 0.
 */
final readonly class MrrSnapshots
{
    public const PAYING = ['active', 'past_due'];

    public function __construct(private TenantDirectory $tenants) {}

    public static function mrr(string $status, ?PackageVersion $version, ?string $period): int
    {
        if ($version === null || ! in_array($status, self::PAYING, true)) {
            return 0;
        }

        return $period === 'yearly' ? intdiv((int) $version->yearly_price_cents, 12) : (int) $version->monthly_price_cents;
    }

    /** Today's snapshot, saved (safe to run again the same day). @return int businesses recorded */
    public function take(): int
    {
        $rows = $this->live();
        $day = now()->toDateString();
        DB::table('subscription_snapshots')->where('day', $day)->delete();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('subscription_snapshots')->insert(array_map(fn ($r) => ['day' => $day, ...$r], $chunk));
        }

        return count($rows);
    }

    /**
     * The snapshot for a day: the saved one, or — for today and the future — as things stand now.
     *
     * @return list<array{tenant_id: string, status: string, package_id: string|null, period: string|null, mrr_cents: int, country: string}>
     */
    public function on(\DateTimeInterface $day): array
    {
        $date = CarbonImmutable::instance($day)->toDateString();
        if ($date >= now()->toDateString()) {
            return $this->live();
        }
        $last = DB::table('subscription_snapshots')->where('day', '<=', $date)->max('day');

        return $last === null ? [] : array_values(DB::table('subscription_snapshots')->where('day', $last)->get(['tenant_id', 'status', 'package_id', 'period', 'mrr_cents', 'country'])
            ->map(fn ($r) => ['tenant_id' => (string) $r->tenant_id, 'status' => (string) $r->status, 'package_id' => $r->package_id, 'period' => $r->period, 'mrr_cents' => (int) $r->mrr_cents, 'country' => (string) $r->country])->all());
    }

    /** The first day a snapshot was saved (history starts there). */
    public function since(): ?string
    {
        $d = DB::table('subscription_snapshots')->min('day');

        return $d === null ? null : (string) $d;
    }

    /** @return list<array{tenant_id: string, status: string, package_id: string|null, period: string|null, mrr_cents: int, country: string}> */
    private function live(): array
    {
        $rows = [];
        $versions = PackageVersion::query()->get()->keyBy('id');
        foreach (TenantSubscription::query()->get() as $s) {
            $tenant = $this->tenants->find((string) $s->tenant_id);
            if ($tenant === null || $tenant->isDemo() || ! $tenant->isActive()) {
                continue; // demos and archived businesses aren't customers
            }
            $version = $versions[$s->package_version_id] ?? null;
            $rows[] = ['tenant_id' => (string) $s->tenant_id, 'status' => (string) $s->status, 'package_id' => $version?->package_id, 'period' => $s->billing_period,
                'mrr_cents' => self::mrr((string) $s->status, $version, $s->billing_period), 'country' => $tenant->countryCode()];
        }

        return $rows;
    }
}
