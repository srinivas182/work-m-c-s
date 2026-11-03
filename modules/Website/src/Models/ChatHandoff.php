<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $message
 * @property list<array{from: string, text: string}> $transcript
 * @property string|null $contact_id
 * @property string|null $lead_id
 * @property Carbon|null $marketing_consent_at
 * @property string $status
 * @property Carbon $created_at
 */
final class ChatHandoff extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['transcript' => 'array', 'marketing_consent_at' => 'datetime'];
    }
}
