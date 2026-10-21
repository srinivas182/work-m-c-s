<?php

declare(strict_types=1);

namespace Wurk\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Audit\Chain\AppendOnly;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * Audit event on the platform chain.
 *
 * @property string $id
 * @property string $scope
 * @property int $seq
 * @property string|null $tenant_id
 * @property string $actor_type
 * @property string|null $actor_identity_id
 * @property string|null $actor_name
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $changes
 * @property string|null $ip
 * @property Carbon $occurred_at
 * @property string $prev_hash
 * @property string $hash
 */
final class PlatformAuditEvent extends Model implements CentralModel
{
    use AppendOnly;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'audit_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array', 'occurred_at' => 'datetime', 'seq' => 'integer'];
    }
}
