<?php

declare(strict_types=1);

namespace Wurk\Records\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $last_document_at
 * @property Carbon|null $flagged_at
 */
final class Patient extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'record_patients';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_document_at' => 'datetime', 'flagged_at' => 'datetime'];
    }
}
