<?php

declare(strict_types=1);

namespace Wurk\Bookings\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\ClassSession;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Bookings\Models\ReservationHours;
use Wurk\Bookings\Models\ReservationSettings;
use Wurk\Bookings\Support\Instant;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Makes the demo sites (website:demo-sites) bookable: the Starter package, a team member, a service
 * and hours every day 08:00–20:00 — so browser tests can book on a published site at any hour.
 * Development and tests only; safe to run again.
 */
final class DemoBookingsCommand extends Command
{
    protected $signature = 'bookings:demo';

    protected $description = 'Make the demo sites bookable (development and tests only)';

    public function handle(TenantDirectory $tenants, TenantContext $context, Catalog $catalog, Entitlements $entitlements): int
    {
        if (app()->environment('production')) {
            $this->error('Demo bookings are for development and tests only.');

            return self::FAILURE;
        }
        $starter = collect($catalog->onSale())->firstWhere('key', 'starter');
        if ($starter === null) {
            $this->error('No Starter package: run packages:seed-placeholders first.');

            return self::FAILURE;
        }
        foreach (glob(base_path('modules/Website/templates/*.json')) ?: [] as $file) {
            $tenant = $tenants->findByHandle('demo-'.(string) (json_decode((string) file_get_contents($file), true)['key'] ?? ''));
            if ($tenant === null || $catalog->subscriptionOf($tenant->tenantId()) !== null) {
                continue;
            }
            $catalog->subscribe($tenant->tenantId(), (string) $starter['id'], 'monthly', allowTrial: true);
            $context->run($tenant, function () use ($context): void {
                $memberId = app(TeamDirectory::class)->add((string) Str::ulid(), 'Demo stylist', 'staff');
                $service = BookingService::query()->create(['name' => 'Consultation', 'duration_minutes' => 30, 'price_cents' => 25000, 'location_type' => 'either']);
                MeetingConnection::query()->create(['tenant_id' => $context->id(), 'provider' => 'fixed', 'fixed_url' => 'https://meet.example.com/demo']);
                DB::connection($context->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $memberId, 'tenant_id' => $context->id()]);
                foreach (range(0, 6) as $day) {
                    BookingHours::query()->create(['member_id' => $memberId, 'weekday' => $day, 'starts' => '08:00', 'ends' => '20:00']);
                }
            });
            if (str_contains($tenant->handle(), 'gym')) { // the gym demo runs classes, one of them online
                $context->run($tenant, function () use ($tenant): void {
                    $tz = $tenant->timezone();
                    foreach (range(1, 7) as $d) {
                        $morning = CarbonImmutable::now($tz)->addDays($d)->setTime(6, 0);
                        ClassSession::query()->create(['title' => 'Morning HIIT', 'starts_at' => Instant::db($morning), 'ends_at' => Instant::db($morning->addMinutes(45)), 'capacity' => 20, 'location_type' => 'in_person', 'price_cents' => 12000]);
                        $evening = CarbonImmutable::now($tz)->addDays($d)->setTime(18, 30);
                        ClassSession::query()->create(['title' => 'Yoga at home', 'starts_at' => Instant::db($evening), 'ends_at' => Instant::db($evening->addMinutes(60)), 'capacity' => 30, 'location_type' => 'online', 'price_cents' => 8000]);
                    }
                });
            }
            if (str_contains($tenant->handle(), 'restaurant')) { // the restaurant demo takes table reservations
                $context->run($tenant, function () use ($context): void {
                    ReservationSettings::query()->updateOrCreate(['tenant_id' => $context->id()], ['enabled' => true, 'covers' => 40, 'sitting_minutes' => 90, 'max_party' => 8, 'notice_minutes' => 60]);
                    foreach (range(0, 6) as $day) {
                        ReservationHours::query()->create(['weekday' => $day, 'starts' => '12:00', 'ends' => '21:00']);
                    }
                });
            }
            $this->line("{$tenant->handle()}: bookable");
        }
        $entitlements->flush();

        return self::SUCCESS;
    }
}
