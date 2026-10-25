<?php

declare(strict_types=1);

namespace Wurk\Bookings\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
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
                $member = app(Team::class)->add((string) Str::ulid(), 'Demo stylist', 'staff');
                $service = BookingService::query()->create(['name' => 'Consultation', 'duration_minutes' => 30, 'price_cents' => 25000]);
                DB::connection($context->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $member->id, 'tenant_id' => $context->id()]);
                foreach (range(0, 6) as $day) {
                    BookingHours::query()->create(['member_id' => $member->id, 'weekday' => $day, 'starts' => '08:00', 'ends' => '20:00']);
                }
            });
            $this->line("{$tenant->handle()}: bookable");
        }
        $entitlements->flush();

        return self::SUCCESS;
    }
}
