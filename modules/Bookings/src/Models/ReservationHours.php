<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property int $weekday
 * @property string $starts
 * @property string $ends
 */
final class ReservationHours extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'reservation_hours';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }
}
