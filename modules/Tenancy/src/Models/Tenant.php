<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Wurk\Tenancy\Contracts\CentralModel;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Events\TenantStatusChanged;
use Wurk\Tenancy\Resolution\TenantResolver;

/**
 * A business subscribed to WURK.
 *
 * @property string $id
 * @property string $handle
 * @property string $name
 * @property string $status
 * @property string $database_model
 * @property string $shard_id
 * @property string $country
 * @property string $timezone
 */
final class Tenant extends Model implements CentralModel, CurrentTenant
{
    use HasUlids;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DELETED = 'deleted';

    protected $fillable = ['handle', 'name', 'status', 'database_model', 'shard_id', 'country', 'timezone'];

    protected static function booted(): void
    {
        // Keep the resolution cache truthful whenever a tenant changes.
        $flush = function (Tenant $tenant): void {
            $resolver = app(TenantResolver::class);
            $resolver->forgetHandle($tenant->handle);

            $originalHandle = $tenant->getOriginal('handle');
            if (is_string($originalHandle) && $originalHandle !== $tenant->handle) {
                $resolver->forgetHandle($originalHandle);
            }

            foreach ($tenant->domains()->pluck('host') as $host) {
                $resolver->forgetHost((string) $host);
            }
        };

        self::saved($flush);
        self::deleted($flush);

        self::updated(function (Tenant $tenant): void {
            if ($tenant->wasChanged('status')) {
                event(new TenantStatusChanged($tenant->id, $tenant->handle, (string) $tenant->getOriginal('status'), $tenant->status));
            }
        });
    }

    /** @return BelongsTo<Shard, $this> */
    public function shard(): BelongsTo
    {
        return $this->belongsTo(Shard::class);
    }

    /** @return HasMany<TenantDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function tenantId(): string
    {
        return $this->id;
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function shardId(): string
    {
        return $this->shard_id;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
