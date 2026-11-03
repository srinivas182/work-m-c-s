<?php

declare(strict_types=1);

namespace Wurk\Crm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $contact_id
 * @property string $pipeline_id
 * @property string $stage_id
 * @property string $title
 * @property int|null $value_cents
 * @property string $source
 * @property string|null $assigned_to
 * @property string|null $branch_id
 * @property Carbon|null $follow_up_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 */
final class Lead extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'leads';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['follow_up_at' => 'datetime', 'reminded_at' => 'datetime', 'closed_at' => 'datetime', 'value_cents' => 'integer'];
    }
}
