<?php

declare(strict_types=1);

namespace Wurk\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A screenshot on a ticket (re-encoded on upload; private).
 *
 * @property string $id
 * @property string $ticket_id
 * @property string $message_id
 * @property string $path
 * @property string $name
 * @property int $size
 * @property Carbon|null $created_at
 */
final class SupportAttachment extends Model implements CentralModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];
}
