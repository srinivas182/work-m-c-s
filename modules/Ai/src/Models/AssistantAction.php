<?php

declare(strict_types=1);

namespace Wurk\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * A change "Ask WURK" proposed (16.2): shown first, made only when confirmed, undone where it still can be.
 *
 * @property string $id
 * @property string|null $question_id
 * @property string $user_id
 * @property string $tool
 * @property array<string, mixed> $params
 * @property string $summary
 * @property list<string> $changes
 * @property string $status
 * @property array<string, mixed>|null $undo
 * @property string|null $link
 * @property Carbon|null $applied_at
 * @property Carbon|null $undone_at
 * @property Carbon|null $created_at
 */
final class AssistantAction extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'assistant_actions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['params' => 'array', 'changes' => 'array', 'undo' => 'array', 'applied_at' => 'datetime', 'undone_at' => 'datetime'];
    }
}
