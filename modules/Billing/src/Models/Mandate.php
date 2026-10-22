<?php

declare(strict_types=1);

namespace Wurk\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string $reference
 * @property string|null $provider_mandate_id
 * @property string $status
 * @property string $bank
 * @property string $account_type
 * @property string $account_masked
 * @property string $holder_name
 * @property array{account_number: string, branch_code: string, id_number: string} $secret
 * @property int $collection_day
 * @property int $max_amount_cents
 * @property string|null $failure
 */
final class Mandate extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted:array', 'collection_day' => 'integer', 'max_amount_cents' => 'integer'];
    }
}
