<?php

declare(strict_types=1);

namespace Wurk\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Wurk\Tenancy\Contracts\CentralModel;

/**
 * Signed, single-use, 7-day invitation. Only a hash of the token is stored.
 *
 * @property string $id
 * @property string $kind staff|member
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $tenant_id
 * @property string $role
 * @property string|null $invited_by
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
final class Invitation extends Model implements CentralModel
{
    use HasUlids;

    public const KIND_STAFF = 'staff';

    public const KIND_MEMBER = 'member';

    protected $fillable = ['kind', 'email', 'phone', 'first_name', 'last_name', 'tenant_id', 'role', 'invited_by', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** Where the invitation was sent (email address or E.164 mobile number). */
    public function destination(): string
    {
        return (string) ($this->email ?? $this->phone);
    }

    public static function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
