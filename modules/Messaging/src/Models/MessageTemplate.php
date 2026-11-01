<?php

declare(strict_types=1);

namespace Wurk\Messaging\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $key
 * @property string $channel
 * @property string|null $subject
 * @property string $text
 * @property string|null $updated_by
 * @property Carbon|null $updated_at
 */
final class MessageTemplate extends Model
{
    use BelongsToTenant, HasUlids;

    public const CREATED_AT = null;

    protected $guarded = [];
}
