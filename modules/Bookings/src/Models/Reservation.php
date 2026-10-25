<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property int $party_size
 * @property string $service_date
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property string $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_email
 * @property string|null $notes
 * @property string $source
 */
final class Reservation extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'reservations';

    protected $guarded = [];

    /** Statuses that take seats. */
    public const ACTIVE = ['confirmed', 'seated'];

    protected function casts(): array
    {
        return ['party_size' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
