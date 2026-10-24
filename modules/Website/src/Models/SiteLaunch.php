<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $tenant_id
 * @property string|null $template_key
 * @property Carbon|null $business_created_at
 * @property Carbon $launched_at
 * @property int|null $minutes
 */
final class SiteLaunch extends Model implements CentralModel
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['business_created_at' => 'datetime', 'launched_at' => 'datetime', 'minutes' => 'integer'];
    }
}
