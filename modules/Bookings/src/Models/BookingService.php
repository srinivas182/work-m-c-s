<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property int $duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int $price_cents
 * @property string $deposit_type
 * @property int $deposit_value
 * @property bool $active
 * @property int $position
 */
final class BookingService extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'booking_services';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'buffer_before_minutes' => 'integer', 'buffer_after_minutes' => 'integer', 'price_cents' => 'integer', 'deposit_value' => 'integer', 'active' => 'boolean', 'position' => 'integer'];
    }
}
