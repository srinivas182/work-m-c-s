<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $tenant_id
 * @property string $billing_email
 * @property string|null $billing_name
 * @property string|null $vat_number
 * @property string|null $default_method_id
 * @property string $collection_method
 * @property string|null $default_mandate_id
 */
final class BillingAccount extends Model implements CentralModel
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
