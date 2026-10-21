<?php

declare(strict_types=1);

namespace Wurk\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string|null $identity_id
 * @property string $channel
 * @property string $destination_hash
 * @property string $code_hash
 * @property string $purpose
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 */
final class OtpChallenge extends Model implements CentralModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['identity_id', 'channel', 'destination_hash', 'code_hash', 'purpose', 'attempts', 'expires_at', 'consumed_at', 'ip'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'attempts' => 'integer'];
    }
}
