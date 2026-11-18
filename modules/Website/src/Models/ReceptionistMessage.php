<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * One item of a receptionist conversation: what the customer or the AI said, a tool call and its result, or a
 * person's reply. `content` holds the provider-neutral item (see Wurk\Ai\Contracts\AiConversations).
 *
 * @property string $id
 * @property string $conversation_id
 * @property int $position
 * @property string $role
 * @property array<string, mixed> $content
 * @property Carbon $created_at
 * @property string|null $external_id WhatsApp's message ID (21.2)
 * @property string|null $delivery sent | delivered | read | failed
 * @property string|null $delivery_error
 * @property array{path: string, mime: string, name: string, bytes: int, kind: string}|array{blocked: string}|null $attachment
 */
final class ReceptionistMessage extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'array', 'attachment' => 'array'];
    }
}
