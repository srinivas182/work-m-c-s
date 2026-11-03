<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $enquiry_id
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string|null $path
 * @property string $status
 * @property Carbon|null $scanned_at
 */
final class EnquiryFile extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'size' => 'integer'];
    }
}
