<?php

declare(strict_types=1);

namespace Wurk\Push\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * One phone (and browser) that gets a person's push notifications (Sprint 26.2).
 *
 * @property string $id
 * @property string $identity_id
 * @property string $endpoint_hash
 * @property string $endpoint
 * @property string $p256dh
 * @property string $auth
 * @property string|null $device
 * @property Carbon|null $last_sent_at
 */
final class PushSubscription extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }
}
