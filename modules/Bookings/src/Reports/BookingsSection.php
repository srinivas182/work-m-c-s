<?php

declare(strict_types=1);

namespace Wurk\Bookings\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

/** Reports → Bookings (Sprint 15.1a): appointments in the period — no-shows, cancellations, value, busiest times. */
final readonly class BookingsSection
{
    public function __construct(private TenantContext $context) {}

    /** @return array<string, mixed> */
    public function build(ReportPeriod $p): array
    {
        $now = $this->bookings($p, $p->from, $p->to);
        $before = $this->bookings($p, $p->previousFrom(), $p->previousTo());
        $kept = fn ($rows) => $rows->where('status', '!=', 'cancelled');
        $value = fn ($rows) => (int) $kept($rows)->sum('price_cents');
        $noShowRate = function ($rows): float {
            $due = $rows->whereIn('status', ['completed', 'no_show'])->count();

            return $due === 0 ? 0.0 : round($rows->where('status', 'no_show')->count() / $due * 100, 1);
        };
        $tz = $this->context->current()->timezone();
        $slots = [];
        $days = [];
        foreach ($kept($now) as $b) {
            $at = CarbonImmutable::parse((string) $b->starts_at)->setTimezone($tz);
            $slot = $at->format('l').' '.$at->format('H').':00';
            $slots[$slot] = ($slots[$slot] ?? 0) + 1;
            $days[$at->format('j M')] = ($days[$at->format('j M')] ?? 0) + 1;
        }
        arsort($slots);
        $highlights = [];
        if ($slots !== []) {
            $highlights[] = 'Your busiest time was '.array_key_first($slots).'.';
        }
        if (($rate = $noShowRate($now)) >= 10) {
            $highlights[] = "{$rate}% of booked customers didn’t show — a reminder or a deposit could help.";
        }

        return [
            'title' => 'Bookings',
            'metrics' => [
                ['label' => 'Bookings', 'value' => $kept($now)->count(), 'previous' => $kept($before)->count(), 'format' => 'count'],
                ['label' => 'Booking value', 'value' => $value($now), 'previous' => $value($before), 'format' => 'money'],
                ['label' => 'No-show rate', 'value' => $noShowRate($now), 'previous' => $noShowRate($before), 'format' => 'percent'],
            ],
            'chart' => ['label' => 'Bookings by day', 'points' => array_map(fn ($d, $c) => ['label' => $d, 'value' => $c], array_keys($days), array_values($days))],
            'rows' => ['columns' => ['Busiest times', 'Bookings'], 'data' => array_map(fn ($s, $c) => [$s, (string) $c], array_slice(array_keys($slots), 0, 5), array_slice(array_values($slots), 0, 5))],
            'extra' => ['columns' => ['Outcome', 'Bookings'], 'data' => [
                ['Completed', (string) $now->where('status', 'completed')->count()], ['No-show', (string) $now->where('status', 'no_show')->count()],
                ['Cancelled', (string) $now->where('status', 'cancelled')->count()], ['Upcoming or confirmed', (string) $now->whereIn('status', ['confirmed', 'pending_payment'])->count()],
            ]],
            'highlights' => $highlights,
        ];
    }

    /** @return Collection<int, \stdClass> */
    private function bookings(ReportPeriod $p, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $db = DB::connection($this->context->connectionName());

        return $db->table('bookings')->leftJoin('booking_services', 'booking_services.id', '=', 'bookings.service_id')
            ->where('bookings.tenant_id', $this->context->id())->where(fn ($q) => $q->whereNull('booking_services.tenant_id')->orWhere('booking_services.tenant_id', $this->context->id()))
            ->whereBetween('bookings.starts_at', [$from, $to])
            ->when($p->branchIds !== null, fn ($q) => $q->whereIn('bookings.branch_id', $p->branchIds))
            ->get(['bookings.status', 'bookings.starts_at', 'booking_services.price_cents']);
    }
}
