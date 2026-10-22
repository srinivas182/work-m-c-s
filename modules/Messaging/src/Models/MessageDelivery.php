<?php

declare(strict_types=1);

namespace Wurk\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * Delivery record for one message. The recipient is stored only as a hash
 * plus a masked form for support ("+27 ••• ••• 4567").
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $channel
 * @property string $driver
 * @property string $to_masked
 * @property string $purpose
 * @property string $status
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property Carbon|null $created_at
 */
final class MessageDelivery extends Model implements CentralModel
{
    use HasUlids;

    protected $fillable = ['tenant_id', 'channel', 'driver', 'to_hash', 'to_masked', 'purpose', 'status', 'provider_message_id', 'error', 'delivered_at'];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }
}
