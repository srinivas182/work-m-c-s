<?php

declare(strict_types=1);

namespace Wurk\Data\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * A full export of a business's data (Sprint 16.6).
 *
 * @property string $id
 * @property string $requested_by
 * @property string $status
 * @property string|null $path
 * @property int $size
 * @property array{tables?: array<string, int>, files?: array<string, int>}|null $contents
 * @property string|null $error
 * @property Carbon|null $ready_at
 * @property Carbon|null $expires_at
 * @property int $downloads
 * @property Carbon|null $created_at
 */
final class DataExport extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'data_exports';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['contents' => 'array', 'size' => 'integer', 'downloads' => 'integer', 'ready_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
