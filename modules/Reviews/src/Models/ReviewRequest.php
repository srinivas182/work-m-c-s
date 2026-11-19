<?php

declare(strict_types=1);

namespace Wurk\Reviews\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * "How was it?" after one visit or order (25.3).
 *
 * @property string $id
 * @property string $visit_key
 * @property string $kind
 * @property string $title
 * @property string $customer_name
 * @property string|null $phone
 * @property string $channel
 * @property Carbon|null $sent_at
 * @property Carbon|null $answered_at
 */
final class ReviewRequest extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'answered_at' => 'datetime'];
    }
}
