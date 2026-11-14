<?php

declare(strict_types=1);

namespace Wurk\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * One message on a ticket — from the business or WURK (internal notes are WURK-only).
 *
 * @property string $id
 * @property string $ticket_id
 * @property string $author_type
 * @property string $author_id
 * @property string $body
 * @property bool $internal
 * @property Carbon|null $created_at
 */
final class SupportMessage extends Model implements CentralModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['internal' => 'boolean'];
    }
}
