<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $page_id
 * @property int $number
 * @property string $title
 * @property string $content
 * @property string|null $note
 * @property string|null $published_by
 * @property Carbon $created_at
 */
final class PageVersion extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['number' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Published versions are immutable.'));
        self::deleting(fn () => throw new \LogicException('Published versions are kept.'));
    }
}
