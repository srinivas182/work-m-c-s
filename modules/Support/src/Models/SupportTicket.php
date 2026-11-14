<?php

declare(strict_types=1);

namespace Wurk\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * A support ticket (Sprint 16.3).
 *
 * @property string $id
 * @property int $number
 * @property string $tenant_id
 * @property string $opened_by
 * @property string $subject
 * @property string $status
 * @property string $priority
 * @property string|null $assignee_id
 * @property string|null $package_key
 * @property int $target_hours
 * @property Carbon $first_response_due_at
 * @property Carbon|null $first_responded_at
 * @property string|null $page
 * @property string|null $browser
 * @property string|null $last_error
 * @property string|null $rating
 * @property string|null $rating_comment
 * @property Carbon|null $solved_at
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $created_at
 */
final class SupportTicket extends Model implements CentralModel
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['number' => 'integer', 'target_hours' => 'integer', 'first_response_due_at' => 'datetime', 'first_responded_at' => 'datetime',
            'solved_at' => 'datetime', 'last_activity_at' => 'datetime'];
    }

    /** @return HasMany<SupportMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<SupportAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'ticket_id');
    }

    public function reference(): string
    {
        return '#'.$this->number;
    }
}
