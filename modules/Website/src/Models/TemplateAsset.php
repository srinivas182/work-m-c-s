<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $template_id
 * @property string $name
 * @property int $width
 * @property int $height
 * @property int $bytes
 * @property array<int, string> $variants
 */
final class TemplateAsset extends Model implements CentralModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['variants' => 'array', 'width' => 'integer', 'height' => 'integer', 'bytes' => 'integer'];
    }
}
