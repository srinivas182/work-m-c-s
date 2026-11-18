<?php

declare(strict_types=1);

namespace Wurk\Crm\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * @property string $id
 * @property string|null $locale the customer's language (24.2)
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property list<string>|null $tags
 * @property string|null $notes
 * @property Carbon|null $marketing_consent_at
 * @property string|null $marketing_consent_source
 * @property string|null $merged_into
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 * @property Carbon|null $erased_at
 */
final class Contact extends Model
{
    use BelongsToTenant, HasUlids;
    use SoftDeletes; // recently deleted, 30 days (16.6c); then erased

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tags' => 'array', 'marketing_consent_at' => 'datetime', 'last_seen_at' => 'datetime', 'erased_at' => 'datetime'];
    }
}
