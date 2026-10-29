<?php

declare(strict_types=1);

namespace Wurk\Crm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $pipeline_id
 * @property string $name
 * @property string $kind
 * @property int $position
 */
final class Stage extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'pipeline_stages';

    protected $guarded = [];

    public $timestamps = false;
}
