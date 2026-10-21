<?php

declare(strict_types=1);

namespace Wurk\Credits\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $country
 * @property string $channel
 * @property int $credits_milli
 * @property Carbon $effective_from
 */
final class CreditRate extends Model implements CentralModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['credits_milli' => 'integer', 'effective_from' => 'datetime'];
    }
}
