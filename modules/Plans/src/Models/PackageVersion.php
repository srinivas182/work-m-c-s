<?php

declare(strict_types=1);

namespace Wurk\Plans\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $package_id
 * @property int $version
 * @property string $currency
 * @property int $monthly_price_cents
 * @property int $yearly_price_cents
 * @property int $trial_days
 * @property int $storage_mb
 * @property int $staff_limit
 * @property bool $multi_branch
 * @property int $max_branches
 * @property bool $custom_domain
 * @property int $monthly_credits
 * @property int $referral_max_levels
 * @property int $referral_max_commission_bp
 * @property int|null $managed_fee_cents
 * @property list<string> $modules
 * @property Carbon|null $created_at
 */
final class PackageVersion extends Model implements CentralModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** Fields that define what was sold; changing any of them creates a new version. */
    public const COMMERCIAL = [
        'monthly_price_cents', 'yearly_price_cents', 'trial_days', 'storage_mb', 'staff_limit', 'multi_branch', 'max_branches',
        'custom_domain', 'monthly_credits', 'referral_max_levels', 'referral_max_commission_bp', 'managed_fee_cents', 'modules',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['modules' => 'array', 'multi_branch' => 'boolean', 'custom_domain' => 'boolean', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Package versions are immutable; create a new version instead.'));
    }
}
