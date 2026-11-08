<?php

declare(strict_types=1);

namespace Wurk\Referrals\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $member_id
 * @property int $cents
 * @property string $status
 * @property string $account_holder
 * @property string $bank
 * @property string|null $account_number
 * @property string $account_last4
 * @property string|null $branch_code
 * @property string|null $reference
 * @property string|null $reason
 * @property string|null $decided_by_name
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 */
final class ReferralPayout extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['account_number'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'cents' => 'integer', 'decided_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
