<?php

declare(strict_types=1);

namespace Wurk\Referrals\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property bool $enabled
 * @property int $self_bp
 * @property list<int> $level_bp
 * @property string $attribution
 * @property int $link_days
 * @property int $return_days
 */
final class ReferralProgramme extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'level_bp' => 'array', 'self_bp' => 'integer', 'link_days' => 'integer', 'return_days' => 'integer'];
    }
}
