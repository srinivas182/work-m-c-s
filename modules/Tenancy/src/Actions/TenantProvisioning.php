<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantProvisioner;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantDomain;
use Wurk\Tenancy\Sharding\ShardConnections;
use Wurk\Tenancy\Support\HandleRules;
use Wurk\Tenancy\Support\TenantRows;

final readonly class TenantProvisioning implements TenantProvisioner
{
    public function __construct(private CreateTenant $create) {}

    public function handleProblem(string $handle): ?string
    {
        $handle = strtolower(trim($handle));

        return HandleRules::problem($handle) ?? (Tenant::query()->where('handle', $handle)->exists() ? 'That name is already taken.' : null);
    }

    public function provision(string $name, string $handle, string $country): CurrentTenant
    {
        return ($this->create)($name, strtolower(trim($handle)), $country);
    }

    public function markDemo(string $tenantId, bool $demo): void
    {
        Tenant::query()->findOrFail($tenantId)->forceFill(['is_demo' => $demo])->save();
    }

    public function archive(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            $new = 'archived-'.strtolower($tenant->id);
            $tenant->domains()->where('type', TenantDomain::TYPE_SUBDOMAIN)->update(['host' => $new.'.'.config('wurk.domains.root')]);
            $tenant->forceFill(['handle' => $new, 'status' => Tenant::STATUS_DELETED])->save(); // the model clears the old handle from the cache
        });
    }

    public function purge(string $tenantId): void
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        if ($tenant->status !== Tenant::STATUS_DELETED) {
            throw new \DomainException('Only an archived business can be removed for good.');
        }
        if ($tenant->purged_at !== null) {
            return;
        }
        $shard = Shard::query()->findOrFail($tenant->shard_id);
        app(TenantRows::class)->delete($tenant->id, app(ShardConnections::class)->register($shard));
        Storage::disk((string) config('filesystems.default'))->deleteDirectory('tenants/'.$tenant->id);
        $tenant->forceFill(['purged_at' => now()])->save();
    }

    public function claimToken(string $tenantId, \DateTimeInterface $until): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode(['t' => $tenantId, 'e' => $until->getTimestamp()])), '+/', '-_'), '=');

        return $body.'.'.hash_hmac('sha256', $body, 'demo-claim|'.(string) config('app.key'));
    }

    public function claimable(string $token): ?CurrentTenant
    {
        [$body, $sig] = array_pad(explode('.', $token, 2), 2, '');
        if ($body === '' || ! hash_equals(hash_hmac('sha256', $body, 'demo-claim|'.(string) config('app.key')), $sig)) {
            return null;
        }
        $d = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (! is_array($d) || ! is_string($d['t'] ?? null) || (int) ($d['e'] ?? 0) < now()->getTimestamp()) {
            return null;
        }
        $tenant = Tenant::query()->find($d['t']);

        return $tenant !== null && $tenant->isDemo() && $tenant->isActive() ? $tenant : null;
    }

    public function claim(string $tenantId, string $name, string $handle): CurrentTenant
    {
        return DB::transaction(function () use ($tenantId, $name, $handle): Tenant {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            if (! $tenant->isDemo() || ! $tenant->isActive()) {
                throw new \DomainException('This demo can no longer be claimed.');
            }
            if ($handle !== $tenant->handle) {
                if (($problem = $this->handleProblem($handle)) !== null) {
                    throw new \DomainException($problem);
                }
                $tenant->domains()->where('type', TenantDomain::TYPE_SUBDOMAIN)->update(['host' => $handle.'.'.config('wurk.domains.root')]);
            }
            $tenant->forceFill(['handle' => $handle, 'name' => $name, 'is_demo' => false])->save();

            return $tenant;
        });
    }
}
