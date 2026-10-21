<?php

declare(strict_types=1);

namespace Wurk\Regions\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $code
 * @property string $name
 * @property string $currency
 * @property int $vat_bp
 * @property string $phone_prefix
 * @property string $timezone
 * @property bool $enabled
 */
final class Country extends Model implements CentralModel
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'currency', 'vat_bp', 'phone_prefix', 'timezone', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'vat_bp' => 'integer'];
    }
}
