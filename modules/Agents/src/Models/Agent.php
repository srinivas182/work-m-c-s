<?php

declare(strict_types=1);

namespace Wurk\Agents\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $identity_id
 * @property string $code
 * @property string|null $sponsor_id
 * @property string $status
 * @property int|null $commission_months
 */
final class Agent extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['commission_months' => 'integer'];
    }
}
