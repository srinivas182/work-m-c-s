<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string|null $member_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reason
 */
final class TimeOff extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'booking_time_off';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public $timestamps = false;
}
