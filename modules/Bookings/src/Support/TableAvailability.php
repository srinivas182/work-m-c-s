<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Wurk\Bookings\Models\Reservation;
use Wurk\Bookings\Models\ReservationHours;
use Wurk\Bookings\Models\ReservationSettings;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Arrival times for a party: every `step_minutes` through the reservation hours (last arrival
 * included), after notice, within 60 days — offered only if the most guests present at any moment
 * during the party's sitting, plus the party, fits the covers.
 */
final readonly class TableAvailability
{
    public function __construct(private TenantContext $context) {}

    /** @return list<array{starts_at: string, label: string}> */
    public function on(string $date, int $party, ?CarbonImmutable $now = null): array
    {
        $settings = ReservationSettings::query()->find($this->context->id());
        if ($settings === null || ! $settings->enabled || $party < 1 || $party > $settings->max_party) {
            return [];
        }
        $tz = $this->context->current()->timezone();
        $now ??= CarbonImmutable::now();
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();
        if ($day->greaterThan($now->setTimezone($tz)->startOfDay()->addDays(Availability::HORIZON_DAYS))) {
            return [];
        }
        $existing = $this->active($date);
        $times = [];
        foreach (ReservationHours::query()->where('weekday', $day->dayOfWeekIso - 1)->orderBy('starts')->get() as $h) {
            $last = CarbonImmutable::parse($date.' '.$h->ends, $tz);
            for ($t = CarbonImmutable::parse($date.' '.$h->starts, $tz); $t->lessThanOrEqualTo($last); $t = $t->addMinutes($settings->step_minutes)) {
                if ($t->lessThan($now->addMinutes($settings->notice_minutes))) {
                    continue;
                }
                if (self::peak($existing, $t, $t->addMinutes($settings->sitting_minutes)) + $party <= $settings->covers) {
                    $times[] = ['starts_at' => $t->utc()->toIso8601String(), 'label' => $t->format('H:i')];
                }
            }
        }

        return $times;
    }

    /** @return Collection<int, Reservation> */
    public function active(string $date): Collection
    {
        return Reservation::query()->where('service_date', $date)->whereIn('status', Reservation::ACTIVE)->get();
    }

    /**
     * The most guests present at any moment between $from and $until.
     *
     * @param  Collection<int, Reservation>  $reservations
     */
    public static function peak(Collection $reservations, CarbonImmutable $from, CarbonImmutable $until): int
    {
        $overlapping = $reservations->filter(fn (Reservation $r) => $r->starts_at->lessThan($until) && $r->ends_at->greaterThan($from));
        $points = $overlapping->map(fn (Reservation $r) => $r->starts_at->greaterThan($from) ? CarbonImmutable::instance($r->starts_at) : $from)->push($from);
        $peak = 0;
        foreach ($points as $p) {
            $load = (int) $overlapping->filter(fn (Reservation $r) => $r->starts_at->lessThanOrEqualTo($p) && $r->ends_at->greaterThan($p))->sum('party_size');
            $peak = max($peak, $load);
        }

        return $peak;
    }
}
