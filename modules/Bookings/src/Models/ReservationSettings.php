<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $tenant_id
 * @property bool $enabled
 * @property int $covers
 * @property int $sitting_minutes
 * @property int $step_minutes
 * @property int $max_party
 * @property int $notice_minutes
 */
final class ReservationSettings extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $table = 'reservation_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'covers' => 'integer', 'sitting_minutes' => 'integer', 'step_minutes' => 'integer', 'max_party' => 'integer', 'notice_minutes' => 'integer'];
    }
}
