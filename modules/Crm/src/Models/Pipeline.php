<?php

declare(strict_types=1);

namespace Wurk\Crm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property int $position
 */
final class Pipeline extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'pipelines';

    protected $guarded = [];
}
