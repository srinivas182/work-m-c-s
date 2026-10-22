<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $description
 * @property int $quantity
 * @property int $excl_cents
 * @property int $vat_cents
 * @property int $total_cents
 */
final class InvoiceLine extends Model implements CentralModel
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];
}
