<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $member_id
 * @property int $weekday
 * @property string $starts
 * @property string $ends
 */
final class BookingHours extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'booking_hours';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public $timestamps = false;
}
