<?php

declare(strict_types=1);

namespace Wurk\Reach\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A business's short number inside WURK's USSD code (25.2).
 *
 * @property string $tenant_id
 * @property string $number
 * @property bool $ussd_on
 * @property bool $sms_on
 * @property string|null $sms_keyword
 */
final class ReachNumber extends Model implements CentralModel
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['tenant_id', 'number', 'ussd_on', 'sms_on', 'sms_keyword'];

    /** The table's defaults, on a new number too (its switches show "on" before it's read back). */
    protected $attributes = ['ussd_on' => true, 'sms_on' => true];

    protected function casts(): array
    {
        return ['ussd_on' => 'boolean', 'sms_on' => 'boolean'];
    }
}
