<?php

declare(strict_types=1);

namespace Wurk\Bookings\Setup;

use Illuminate\Support\Facades\DB;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\SetupStep;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Setting up by talking (23.1), bookings: when the owner wants online bookings, the services they named (with their
 * prices and lengths) become bookable with the owner, during the opening hours they gave. Services that already exist
 * by name are left alone.
 */
final readonly class BookingsSetupStep implements SetupStep
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private Entitlements $entitlements) {}

    public function order(): int
    {
        return 20;
    }

    public function preview(array $brief): array
    {
        if (! $brief['bookings'] || $brief['services'] === []) {
            return [];
        }
        if (! $this->entitlements->entitled($this->context->id(), 'bookings')) {
            return ['Online bookings aren’t in your package, so your services are shown on the website only'];
        }
        $new = $this->newServices($brief);
        $open = array_filter((array) $brief['hours'], fn ($h) => ($h['opens'] ?? '') !== '');

        return array_values(array_filter([
            $new === [] ? null : 'Customers can book '.count($new).' '.(count($new) === 1 ? 'service' : 'services').' online with you',
            $open === [] ? null : 'You can be booked during your opening hours',
        ]));
    }

    public function apply(array $brief, string $by): array
    {
        if (! $brief['bookings'] || $brief['services'] === [] || ! $this->entitlements->entitled($this->context->id(), 'bookings')) {
            return [];
        }
        $member = collect($this->team->roster())->firstWhere('identity_id', $by)['id'] ?? null;
        $new = $this->newServices($brief);
        DB::connection($this->context->connectionName())->transaction(function () use ($brief, $member, $new): void {
            foreach ($new as $s) {
                $service = BookingService::query()->create(['name' => $s['name'], 'duration_minutes' => $s['minutes'], 'price_cents' => $s['price_cents'], 'active' => true]);
                if ($member !== null) {
                    DB::connection($this->context->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $member, 'tenant_id' => $this->context->id()]);
                }
            }
            if ($member !== null && ! BookingHours::query()->where('member_id', $member)->exists()) {
                foreach (array_values((array) $brief['hours']) as $weekday => $h) {
                    if (($h['opens'] ?? '') !== '') {
                        BookingHours::query()->create(['member_id' => $member, 'weekday' => $weekday, 'starts' => $h['opens'], 'ends' => $h['closes']]);
                    }
                }
            }
        });

        return $new === [] ? [] : [count($new).' '.(count($new) === 1 ? 'service' : 'services').' can be booked online'];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return list<array{name: string, price_cents: int, minutes: int}>
     */
    private function newServices(array $brief): array
    {
        $existing = array_map('mb_strtolower', BookingService::query()->pluck('name')->all());

        return array_values(array_filter((array) $brief['services'], fn ($s) => ! in_array(mb_strtolower($s['name']), $existing, true)));
    }
}
