<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $name
 * @property int $credits
 * @property int $price_cents
 * @property bool $active
 * @property int $display_order
 */
final class CreditPack extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'credits' => 'integer', 'price_cents' => 'integer'];
    }
}
