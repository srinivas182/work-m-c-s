<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $kind
 * @property string|null $label
 * @property string|null $keywords
 * @property string $answer
 * @property string $link_kind
 * @property string|null $link_target
 * @property string|null $fact
 * @property bool $edited
 * @property int $position
 */
final class ChatAnswer extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['edited' => 'boolean', 'position' => 'integer'];
    }
}
