<?php

declare(strict_types=1);

namespace Wurk\Bookings\Today;

use Carbon\CarbonImmutable;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Platform\Money\Money;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Today → My bookings (Sprint 26.2): this person's appointments for the rest of today, with Start, Done and
 * No-show, and "Paid in person" (Sprint 22.3) for what's still to pay. Only for someone who takes bookings.
 */
final readonly class MyBookings
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private InPersonPayments $inPerson) {}

    /** @return array<string, mixed>|null */
    public function section(string $identityId): ?array
    {
        $member = collect($this->team->roster())->first(fn (array $m) => $m['identity_id'] === $identityId && $m['active']);
        if ($member === null) {
            return null;
        }
        $tz = $this->context->current()->timezone();
        $now = CarbonImmutable::now($tz);
        $bookings = Booking::query()->where('member_id', $member['id'])->whereIn('status', ['confirmed', 'completed'])
            ->where('starts_at', '>=', $now->startOfDay()->utc())->where('starts_at', '<', $now->endOfDay()->utc())->orderBy('starts_at')->get();
        if ($bookings->isEmpty() && ! Booking::query()->where('member_id', $member['id'])->exists()) {
            return null; // doesn't take bookings
        }
        $services = BookingService::withTrashed()->whereIn('id', $bookings->pluck('service_id')->unique())->get(['id', 'name', 'price_cents'])->keyBy('id');
        $taken = $this->inPerson->takenForMany('booking', array_values($bookings->pluck('id')->map(fn ($id) => (string) $id)->all()));
        $base = '/'.$this->context->current()->handle().'/bookings/today/';

        return [
            'title' => __('My bookings today'),
            'empty' => __('No more bookings today.'),
            'link' => ['label' => __('Calendar'), 'url' => '/'.$this->context->current()->handle().'/bookings'],
            'items' => array_values($bookings->map(function (Booking $b) use ($services, $taken, $tz, $base): array {
                $service = $services[$b->service_id] ?? null;
                $online = $b->payment_reference !== null && $b->paid_at !== null && $b->refunded_at === null ? $b->deposit_cents - (int) $b->wallet_cents : 0;
                $due = max(0, (int) ($service->price_cents ?? 0) - $online - (int) ($taken[$b->id] ?? 0));
                $done = $b->status === 'completed';
                $actions = [];
                if (! $done) {
                    if ($b->started_at === null) {
                        $actions[] = ['label' => __('Start'), 'url' => $base.$b->id.'/start', 'data' => [], 'tone' => 'primary'];
                    }
                    $actions[] = ['label' => __('Done'), 'url' => $base.$b->id.'/status', 'data' => ['status' => 'completed'], 'tone' => $b->started_at === null ? 'secondary' : 'primary'];
                    if ($b->started_at === null) {
                        $actions[] = ['label' => __('No-show'), 'url' => $base.$b->id.'/status', 'data' => ['status' => 'no_show'], 'tone' => 'danger'];
                    }
                }
                if ($due > 0) {
                    foreach (InPersonPayments::METHODS as $method => $label) {
                        $actions[] = ['label' => __('Paid: :how', ['how' => __($label === 'SnapScan / Zapper at the counter' ? 'QR' : $label)]), 'url' => $base.$b->id.'/paid',
                            'data' => ['method' => $method], 'tone' => 'secondary'];
                    }
                }

                return ['id' => $b->id, 'title' => $b->customer_name, 'detail' => trim(($service->name ?? '').($due > 0 ? ' · '.__('to pay').' '.Money::format($due) : '')),
                    'time' => CarbonImmutable::parse($b->starts_at)->setTimezone($tz)->format('H:i'),
                    'badge' => $done ? __('Done') : ($b->started_at !== null ? __('Started') : null), 'url' => null, 'actions' => $actions, 'due_cents' => $due];
            })->all()),
        ];
    }
}
