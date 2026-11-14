<?php

declare(strict_types=1);

namespace Wurk\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * WURK staff asking for, or holding, access to a business's back office (Sprint 16.4).
 *
 * @property string $id
 * @property string $ticket_id
 * @property string $tenant_id
 * @property string $staff_id
 * @property string $requested_level
 * @property string $reason
 * @property string $status
 * @property string|null $level
 * @property int|null $minutes
 * @property string|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon|null $ends_at
 * @property string|null $ended_by
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 */
final class SupportAccessGrant extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['minutes' => 'integer', 'decided_at' => 'datetime', 'ends_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function isLive(): bool
    {
        return $this->status === 'active' && $this->ends_at !== null && $this->ends_at->isFuture();
    }
}
