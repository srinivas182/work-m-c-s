<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Wurk\Bookings\Models\BookingService;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/** Which branches offer a service (none listed = every branch), and its price at a branch (an override, or its own price). */
final readonly class BranchServices
{
    public function __construct(private TenantContext $context, private BranchDirectory $branches) {}

    /** A requested branch if it's open, otherwise the main branch. */
    public function resolve(?string $branchId): string
    {
        $open = array_column($this->branches->open(), 'id');

        return $branchId !== null && in_array($branchId, $open, true) ? $branchId : $this->branches->main();
    }

    public function offeredAt(BookingService $service, string $branchId): bool
    {
        $listed = $this->rows($service->id)->pluck('branch_id')->all();

        return $listed === [] || in_array($branchId, $listed, true);
    }

    public function priceAt(BookingService $service, ?string $branchId): int
    {
        if ($branchId === null) {
            return $service->price_cents;
        }
        $override = $this->rows($service->id)->firstWhere('branch_id', $branchId)?->price_cents;

        return $override === null ? $service->price_cents : (int) $override;
    }

    /**
     * Save where a service is offered, and any price per branch.
     *
     * @param  array<string, int|null>  $branches  branch id => price override (null = the service's price); empty = every branch
     */
    public function set(BookingService $service, array $branches): void
    {
        $db = DB::connection($this->context->connectionName());
        $db->transaction(function () use ($db, $service, $branches): void {
            $db->table('booking_service_branch')->where('service_id', $service->id)->delete();
            foreach ($branches as $branchId => $price) {
                $db->table('booking_service_branch')->insert(['service_id' => $service->id, 'branch_id' => $branchId, 'tenant_id' => $this->context->id(), 'price_cents' => $price]);
            }
        });
    }

    /** @return Collection<int, \stdClass> */
    private function rows(string $serviceId): Collection
    {
        return DB::connection($this->context->connectionName())->table('booking_service_branch')->where('service_id', $serviceId)->get(['branch_id', 'price_cents']);
    }
}
