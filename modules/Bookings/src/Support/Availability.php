<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\TimeOff;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Free start times for a service on a date, in the business's time zone: working hours (breaks are
 * the gaps), the service's length and buffers (which must fit inside working hours), time off (the
 * team member's or the whole business's), existing bookings, minimum notice and how far ahead.
 */
/** Not final: tests stand in for a race (both customers saw the time as free). */
readonly class Availability
{
    public const SLOT = 5;          // minutes: bookings claim 5-minute slots

    public const STEP = 15;         // minutes between offered start times

    public const NOTICE = 60;       // minutes of notice needed

    public const HORIZON_DAYS = 60; // how far ahead customers may book

    public function __construct(private TenantContext $context) {}

    /**
     * @param  string  $date  local date, Y-m-d
     * @return list<array{starts_at: string, members: list<string>}> UTC ISO times, members free at each
     */
    public function on(BookingService $service, string $date, ?string $memberId = null, ?CarbonImmutable $now = null, ?string $branchId = null): array
    {
        $branches = app(BranchServices::class);
        $branch = $branches->resolve($branchId);
        if (! $branches->offeredAt($service, $branch)) {
            return [];
        }
        $tz = $this->context->current()->timezone();
        $now ??= CarbonImmutable::now();
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();
        if ($day->greaterThan($now->setTimezone($tz)->startOfDay()->addDays(self::HORIZON_DAYS))) {
            return [];
        }
        $members = DB::connection($this->context->connectionName())->table('booking_service_member')
            ->where('service_id', $service->id)->when($memberId !== null, fn ($q) => $q->where('member_id', $memberId))->pluck('member_id')->all();
        $earliest = $now->addMinutes(self::NOTICE);
        $before = $service->buffer_before_minutes;
        $length = $before + $service->duration_minutes + $service->buffer_after_minutes;
        $free = [];

        foreach ($members as $member) {
            $member = (string) $member;
            $hours = BookingHours::query()->where('member_id', $member)->where('branch_id', $branch)->where('weekday', $day->dayOfWeekIso - 1)->orderBy('starts')->get(); // their hours at this branch
            $dayStartUtc = $day->utc();
            $dayEndUtc = $day->addDay()->utc();
            $off = TimeOff::query()->where(fn ($q) => $q->whereNull('member_id')->orWhere('member_id', $member))
                ->where('starts_at', '<', Instant::db($dayEndUtc))->where('ends_at', '>', Instant::db($dayStartUtc))->get();
            $taken = array_flip(DB::connection($this->context->connectionName())->table('booking_slots')->where('member_id', $member)
                ->whereBetween('slot_at', [Instant::db($dayStartUtc->subHours(3)), Instant::db($dayEndUtc->addHours(3))])->pluck('slot_at')
                ->map(fn ($t) => Instant::fromDb((string) $t)->getTimestamp())->all());

            foreach ($hours as $h) {
                $open = CarbonImmutable::parse($day->format('Y-m-d').' '.$h->starts, $tz);
                $close = CarbonImmutable::parse($day->format('Y-m-d').' '.$h->ends, $tz);
                for ($blockFrom = $open; $blockFrom->addMinutes($length)->lessThanOrEqualTo($close); $blockFrom = $blockFrom->addMinutes(self::STEP)) {
                    $start = $blockFrom->addMinutes($before);
                    $blockUntil = $blockFrom->addMinutes($length);
                    if ($start->lessThan($earliest)) {
                        continue;
                    }
                    if ($off->contains(fn (TimeOff $t) => $t->starts_at->lessThan($blockUntil) && $t->ends_at->greaterThan($blockFrom))) {
                        continue;
                    }
                    foreach (self::slots($blockFrom, $blockUntil) as $slot) {
                        if (isset($taken[$slot->getTimestamp()])) {
                            continue 2;
                        }
                    }
                    $free[$start->utc()->toIso8601String()][] = $member;
                }
            }
        }
        ksort($free);
        $out = [];
        foreach ($free as $startsAt => $ids) {
            $out[] = ['starts_at' => (string) $startsAt, 'members' => array_values(array_unique($ids))];
        }

        return $out;
    }

    /**
     * The 5-minute slots a time range claims.
     *
     * @return list<CarbonImmutable>
     */
    public static function slots(CarbonImmutable $from, CarbonImmutable $until): array
    {
        $slots = [];
        for ($t = $from->utc(); $t->lessThan($until); $t = $t->addMinutes(self::SLOT)) {
            $slots[] = $t;
        }

        return $slots;
    }
}
