<?php

declare(strict_types=1);

namespace Wurk\Ai\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $task
 * @property string $provider
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_usd_micro
 * @property string $status
 * @property string|null $error
 * @property bool $fallback
 * @property int $duration_ms
 * @property Carbon $created_at
 */
final class AiRequest extends Model implements CentralModel
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fallback' => 'boolean', 'created_at' => 'datetime', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cost_usd_micro' => 'integer', 'duration_ms' => 'integer'];
    }
}
