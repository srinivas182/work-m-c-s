<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * An AI receptionist conversation (Sprint 19.2).
 *
 * @property string $id
 * @property string $channel
 * @property string $visitor
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $contact_id
 * @property string $status
 * @property string|null $outcome
 * @property string|null $language
 * @property int $replies
 * @property int $credits_milli
 * @property bool $keep
 * @property Carbon $last_message_at
 * @property Carbon $created_at
 */
final class ReceptionistConversation extends Model
{
    use BelongsToTenant, HasUlids;

    public const STATUSES = ['ai', 'waiting', 'person', 'closed'];

    /** Days a conversation that led nowhere is kept (the customer is told). */
    public const KEEP_DAYS = 30;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['keep' => 'boolean', 'replies' => 'integer', 'credits_milli' => 'integer', 'last_message_at' => 'datetime'];
    }

    /** @return HasMany<ReceptionistMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ReceptionistMessage::class, 'conversation_id');
    }
}
