<?php

declare(strict_types=1);

namespace Wurk\Referrals\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $contact_id
 * @property string $code
 * @property string|null $referrer_id
 * @property Carbon|null $referred_at
 * @property Carbon $created_at
 */
final class ReferralMember extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['referred_at' => 'datetime'];
    }
}
