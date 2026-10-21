<?php

declare(strict_types=1);

namespace Wurk\Plans\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property string $type
 * @property string $visibility
 * @property int $display_order
 * @property string $status
 * @property string|null $current_version_id
 * @property-read PackageVersion|null $currentVersion
 */
final class Package extends Model implements CentralModel
{
    use HasUlids;

    public const SELF_MANAGED = 'self_managed';

    public const MANAGED = 'managed';

    protected $fillable = ['key', 'name', 'description', 'type', 'visibility', 'display_order', 'status', 'current_version_id'];

    /** @return BelongsTo<PackageVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class, 'current_version_id');
    }

    /** @return HasMany<PackageVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class)->orderByDesc('version');
    }
}
