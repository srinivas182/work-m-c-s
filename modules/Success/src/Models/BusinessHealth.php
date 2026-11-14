<?php

declare(strict_types=1);

namespace Wurk\Success\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A business's latest health score (Sprint 16.5).
 *
 * @property string $tenant_id
 * @property string $name
 * @property string|null $package_key
 * @property int $score
 * @property int|null $previous_score
 * @property string $grade
 * @property list<array{key: string, points: int, reason: string, action: string}> $signals
 * @property int $mrr_cents
 * @property Carbon|null $at_risk_since
 * @property Carbon $computed_at
 */
final class BusinessHealth extends Model implements CentralModel
{
    public const GRADES = ['healthy' => 'Healthy', 'watch' => 'Watch', 'at_risk' => 'At risk'];

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'business_health';

    protected $primaryKey = 'tenant_id';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['signals' => 'array', 'score' => 'integer', 'previous_score' => 'integer', 'mrr_cents' => 'integer', 'at_risk_since' => 'datetime', 'computed_at' => 'datetime'];
    }

    public static function gradeFor(int $score): string
    {
        return $score >= 80 ? 'healthy' : ($score >= 50 ? 'watch' : 'at_risk');
    }
}
