<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $tenant_id
 * @property string|null $payout_subaccount
 * @property string|null $payout_label
 * @property int $cancellation_hours
 * @property string|null $policy
 */
final class BookingSettings extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $table = 'booking_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cancellation_hours' => 'integer'];
    }
}
