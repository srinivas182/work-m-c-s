<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A move of a business between shards, or a restore of one business from a backup (Sprint 18.4a).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $source
 * @property string|null $from_shard_id
 * @property string $to_shard_id
 * @property string $status
 * @property array<string, int>|null $rows
 * @property string|null $error
 * @property string|null $started_by
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $clear_after
 */
final class TenantMove extends Model implements CentralModel
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rows' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'clear_after' => 'datetime'];
    }
}
