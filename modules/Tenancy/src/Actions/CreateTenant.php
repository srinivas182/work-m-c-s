<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Events\TenantProvisioned;
use Wurk\Tenancy\Models\Branch;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantDomain;
use Wurk\Tenancy\Support\HandleRules;

/**
 * Provisions a business: picks the least-loaded shard, creates the tenant and its
 * subdomain, creates the default branch on the shard, then activates the tenant.
 * Used by `tenants:create` now and by self-service signup in Sprint 3.
 */
final readonly class CreateTenant
{
    public function __construct(private TenantContext $context) {}

    public function __invoke(
        string $name,
        string $handle,
        string $country = 'ZA',
        string $timezone = 'Africa/Johannesburg',
        ?Shard $shard = null,
    ): Tenant {
        $handle = strtolower(trim($handle));

        if (($problem = HandleRules::problem($handle)) !== null) {
            throw ValidationException::withMessages(['handle' => $problem]);
        }

        if (Tenant::query()->where('handle', $handle)->exists()) {
            throw ValidationException::withMessages(['handle' => 'That name is already taken.']);
        }

        /** @var Tenant $tenant */
        $tenant = DB::transaction(function () use ($name, $handle, $country, $timezone, $shard): Tenant {
            $target = $shard !== null
                ? Shard::query()->lockForUpdate()->findOrFail($shard->id)
                : Shard::query()->available()->lockForUpdate()->first();

            if ($target === null || $target->tenant_count >= $target->capacity) {
                throw new RuntimeException('No shard has capacity for a new business.');
            }

            $tenant = Tenant::query()->create([
                'handle' => $handle,
                'name' => $name,
                'status' => Tenant::STATUS_PROVISIONING,
                'database_model' => 'shared',
                'shard_id' => $target->id,
                'country' => strtoupper($country),
                'timezone' => $timezone,
            ]);

            $tenant->domains()->create([
                'host' => $handle.'.'.config('wurk.domains.root'),
                'type' => TenantDomain::TYPE_SUBDOMAIN,
                'is_primary' => true,
                'verified_at' => now(),
            ]);

            $target->increment('tenant_count');

            return $tenant;
        });

        try {
            $this->context->run($tenant, fn () => Branch::query()->create([
                'name' => 'Main branch',
                'code' => 'main',
                'timezone' => $timezone,
                'is_default' => true,
            ]));
        } catch (Throwable $e) {
            // Roll back the central records so the handle can be retried.
            DB::transaction(function () use ($tenant): void {
                Shard::query()->whereKey($tenant->shard_id)->decrement('tenant_count');
                $tenant->delete();
            });

            throw $e;
        }

        $tenant->forceFill(['status' => Tenant::STATUS_ACTIVE])->saveQuietly();
        event(new TenantProvisioned($tenant->id, $tenant->handle, $tenant->name, $tenant->shard_id));

        return $tenant;
    }
}
