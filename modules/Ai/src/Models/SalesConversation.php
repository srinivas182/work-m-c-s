<?php

declare(strict_types=1);

namespace Wurk\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A conversation with WURK's sales assistant (Sprint 19.5).
 *
 * @property string $id
 * @property string $visitor
 * @property list<array<string, mixed>> $items
 * @property int $replies
 * @property string|null $outcome
 * @property Carbon $last_message_at
 * @property Carbon $created_at
 */
final class SalesConversation extends Model implements CentralModel
{
    use HasUlids;

    /** Days a conversation is kept (the visitor is told). */
    public const KEEP_DAYS = 30;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'replies' => 'integer', 'last_message_at' => 'datetime'];
    }
}
