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
 * @property string $currency_symbol
 * @property int $currency_decimals
 * @property string $decimal_separator
 * @property string $thousands_separator
 * @property bool $symbol_after
 * @property string $tax_name
 * @property bool $prices_include_tax
 * @property int $phone_digits
 * @property int $week_starts_on
 * @property list<string>|null $languages
 * @property list<string>|null $payment_providers
 * @property string|null $sms_provider
 * @property string|null $ussd_provider
 * @property string $returns_template
 */
final class Country extends Model implements CentralModel
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'currency', 'vat_bp', 'phone_prefix', 'timezone', 'enabled', 'currency_symbol', 'currency_decimals', 'decimal_separator',
        'thousands_separator', 'symbol_after', 'tax_name', 'prices_include_tax', 'phone_digits', 'week_starts_on', 'languages', 'payment_providers', 'sms_provider',
        'ussd_provider', 'returns_template'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'vat_bp' => 'integer', 'currency_decimals' => 'integer', 'symbol_after' => 'boolean', 'prices_include_tax' => 'boolean',
            'phone_digits' => 'integer', 'week_starts_on' => 'integer', 'languages' => 'array', 'payment_providers' => 'array'];
    }
}
