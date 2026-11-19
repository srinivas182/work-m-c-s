<?php

declare(strict_types=1);

namespace Wurk\Reviews\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Wurk\Tenancy\Contracts\BelongsToTenant;

/**
 * A customer's review (25.3). Hidden only for a stated reason; a hidden review still counts in the average.
 *
 * @property string $id
 * @property string|null $request_id
 * @property string $name
 * @property string $title
 * @property int $rating
 * @property string|null $comment
 * @property string|null $private_note
 * @property string|null $reply
 * @property Carbon|null $replied_at
 * @property string|null $hidden_reason
 * @property string|null $hidden_by
 * @property Carbon|null $hidden_at
 * @property Carbon $created_at
 */
final class Review extends Model
{
    use BelongsToTenant, HasUlids;

    /** The only reasons an owner may hide a review. */
    public const HIDE_REASONS = ['abusive' => 'Abusive or hateful', 'personal_data' => 'Shares someone’s personal details', 'not_a_customer' => 'Not from a customer'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'replied_at' => 'datetime', 'hidden_at' => 'datetime'];
    }
}
