<?php

declare(strict_types=1);

namespace Wurk\Website\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string $form
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $service
 * @property string $message
 * @property Carbon $consented_at
 * @property string $ip_hash
 * @property string $status
 * @property Carbon $created_at
 */
final class Enquiry extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['consented_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
