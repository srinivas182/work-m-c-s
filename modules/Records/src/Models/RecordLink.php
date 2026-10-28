<?php

declare(strict_types=1);

namespace Wurk\Records\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $token_hash
 * @property string $patient_id
 * @property string|null $code_hash
 * @property Carbon|null $code_expires_at
 * @property int $attempts
 * @property Carbon $expires_at
 */
final class RecordLink extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'token_hash';

    protected $keyType = 'string';

    protected $table = 'record_links';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['code_expires_at' => 'datetime', 'expires_at' => 'datetime', 'attempts' => 'integer'];
    }
}
