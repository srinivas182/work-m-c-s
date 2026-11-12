<?php

declare(strict_types=1);

namespace Wurk\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string|null $summary
 * @property int $position
 */
final class HelpCategory extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];
}
