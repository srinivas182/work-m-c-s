<?php

declare(strict_types=1);

namespace Wurk\Plans\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $tenant_id
 * @property string $package_id
 * @property string $package_version_id
 * @property string $status
 * @property string $billing_period
 * @property Carbon $started_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $past_due_since
 * @property string|null $pending_package_id
 * @property string|null $pending_period
 * @property-read PackageVersion|null $version
 * @property-read Package|null $package
 */
final class TenantSubscription extends Model implements CentralModel
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['tenant_id', 'package_id', 'package_version_id', 'status', 'billing_period', 'current_period_start', 'current_period_end', 'past_due_since', 'pending_package_id', 'pending_period', 'started_at', 'trial_ends_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'trial_ends_at' => 'datetime', 'current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'past_due_since' => 'datetime'];
    }

    /** @return BelongsTo<PackageVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'package_version_id');
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
