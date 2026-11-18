<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $template_id
 * @property string $name
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property array<int, string> $variants
 * @property string|null $slot the design's photo slot this fills (Sprint 20.3), e.g. "hero"
 * @property string|null $source_url
 * @property string|null $photographer
 * @property string|null $licence
 * @property bool $ai_generated
 * @property string|null $added_by
 * @property Carbon $created_at
 */
final class TemplateAsset extends Model implements CentralModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['variants' => 'array', 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer', 'ai_generated' => 'boolean', 'created_at' => 'datetime'];
    }
}
