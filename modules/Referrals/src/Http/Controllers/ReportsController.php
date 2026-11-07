<?php

declare(strict_types=1);

namespace Wurk\Referrals\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Referrals\Support\ReferralReports;
use Wurk\Tenancy\Contracts\TenantContext;

/** Customers → Referral reports (Sprint 13.9a). */
final class ReportsController
{
    public const PERIODS = ['month' => 'This month', '30d' => 'Last 30 days', 'year' => 'This year', 'all' => 'All time'];

    public function __invoke(Request $request, TenantContext $context, ReferralReports $reports): Response
    {
        $period = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'month';
        [$from, $to] = self::range($period, $context->current()->timezone());

        return Inertia::render('Referrals::Reports', [
            'period' => $period, 'periods' => self::PERIODS,
            'cost' => $reports->cost($from, $to), 'top' => $reports->topReferrers($from, $to),
            'liability' => $reports->liability(), 'payouts' => $reports->payouts($from, $to),
        ]);
    }

    /**
     * The period on the business's own calendar, in the time zone WURK stores times in (the app's — not UTC: converting
     * to UTC left the last two hours out and moved month boundaries).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function range(string $period, string $tz): array
    {
        $now = CarbonImmutable::now($tz);
        $from = match ($period) {
            '30d' => $now->subDays(30),
            'year' => $now->startOfYear(),
            'all' => CarbonImmutable::parse('2000-01-01 00:00', $tz),
            default => $now->startOfMonth(),
        };

        $stored = (string) config('app.timezone');

        return [$from->setTimezone($stored), $now->setTimezone($stored)];
    }
}
