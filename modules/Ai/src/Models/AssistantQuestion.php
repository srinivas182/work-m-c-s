<?php

declare(strict_types=1);

namespace Wurk\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * A question asked of "Ask WURK" (16.2).
 *
 * @property string $id
 * @property string $user_id
 * @property string $question
 * @property string|null $answer
 * @property list<array{title: string, url: string}>|null $sources
 * @property string|null $tool
 * @property bool $free
 * @property int $charged_milli
 * @property string $status
 * @property Carbon|null $created_at
 */
final class AssistantQuestion extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'assistant_questions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sources' => 'array', 'free' => 'boolean', 'charged_milli' => 'integer'];
    }
}
