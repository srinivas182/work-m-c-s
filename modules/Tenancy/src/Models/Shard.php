<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A shared tenant database. Connection settings are encrypted at rest.
 *
 * @property string $id
 * @property string $name
 * @property array<string, mixed> $connection
 * @property string $status
 * @property int $capacity
 * @property int $tenant_count
 * @property int $schema_version
 * @property Carbon|null $migrated_at
 */
final class Shard extends Model implements CentralModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DRAINING = 'draining';

    public const STATUS_FULL = 'full';

    protected $fillable = ['name', 'connection', 'status', 'capacity', 'tenant_count', 'schema_version', 'migrated_at'];

    protected $hidden = ['connection'];

    protected function casts(): array
    {
        return [
            'connection' => 'encrypted:array',
            'capacity' => 'integer',
            'tenant_count' => 'integer',
            'schema_version' => 'integer',
            'migrated_at' => 'datetime',
        ];
    }

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /**
     * Active shards with spare capacity, least loaded first.
     *
     * @param  Builder<Shard>  $query
     */
    public function scopeAvailable(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)
            ->whereColumn('tenant_count', '<', 'capacity')
            ->orderBy('tenant_count')
            ->orderBy('name');
    }
}
