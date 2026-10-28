<?php

declare(strict_types=1);

namespace Wurk\Records\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $patient_id
 * @property string|null $original_id
 * @property int $version
 * @property string $title
 * @property string|null $note
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property string $uploaded_by
 * @property Carbon $created_at
 */
final class Document extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'record_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer', 'size' => 'integer'];
    }
}
