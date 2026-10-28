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
 * @property string $message_channel
 * @property bool $remind_24h
 * @property bool $remind_2h
 * @property bool $health_practice
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
        return ['health_practice' => 'boolean', 'cancellation_hours' => 'integer', 'remind_24h' => 'boolean', 'remind_2h' => 'boolean'];
    }
}
