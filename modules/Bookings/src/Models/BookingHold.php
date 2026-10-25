<?php

declare(strict_types=1);

namespace Wurk\Bookings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $reference
 * @property string $tenant_id
 * @property Carbon $expires_at
 */
final class BookingHold extends Model implements CentralModel
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'reference';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
