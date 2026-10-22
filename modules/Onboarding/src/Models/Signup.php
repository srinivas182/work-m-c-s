<?php

declare(strict_types=1);

namespace Wurk\Onboarding\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * @property string $id
 * @property string $fingerprint
 * @property string $step
 * @property array<string, mixed> $data
 * @property Carbon|null $verified_at
 * @property string|null $tenant_id
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 */
final class Signup extends Model implements CentralModel
{
    use HasUlids;

    public const STEPS = ['details', 'verify', 'package', 'address', 'finish'];

    protected $fillable = ['fingerprint', 'step', 'data', 'verified_at', 'tenant_id', 'completed_at', 'ip'];

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'verified_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->completed_at === null && $this->created_at->gt(now()->subDays(7));
    }
}
