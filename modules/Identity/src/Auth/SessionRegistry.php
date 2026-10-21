<?php

declare(strict_types=1);

namespace Wurk\Identity\Auth;

use Illuminate\Http\Request;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\IdentitySession;

/**
 * Tracks signed-in devices so people can review them and sign out others,
 * whatever session store is in use. Session ids are stored only as hashes.
 */
final class SessionRegistry
{
    public static function key(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public const SESSION_KEY = 'auth.device';

    /**
     * Registers this device and remembers its key in the session itself, so the
     * record survives session id regeneration.
     */
    public function start(Request $request, Identity $identity, string $surface): void
    {
        $key = self::key($request->session()->getId());
        $request->session()->put(self::SESSION_KEY, $key);

        IdentitySession::query()->updateOrCreate(['id' => $key], [
            'identity_id' => $identity->id,
            'surface' => $surface,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
            'last_active_at' => now(),
            'revoked_at' => null,
        ]);
    }

    public function currentKey(Request $request): ?string
    {
        $key = $request->session()->get(self::SESSION_KEY);

        return is_string($key) ? $key : null;
    }

    public function current(Request $request): ?IdentitySession
    {
        $key = $this->currentKey($request);

        return $key === null ? null : IdentitySession::query()->find($key);
    }

    /** Updates last activity at most once a minute. */
    public function touch(IdentitySession $session): void
    {
        if ($session->last_active_at->lt(now()->subMinute())) {
            $session->forceFill(['last_active_at' => now()])->save();
        }
    }

    public function end(Request $request): void
    {
        $key = $this->currentKey($request);
        if ($key !== null) {
            IdentitySession::query()->whereKey($key)->update(['revoked_at' => now()]);
        }
    }

    public function revokeOthers(Identity $identity, Request $request): int
    {
        return IdentitySession::query()->where('identity_id', $identity->id)
            ->whereKeyNot((string) $this->currentKey($request))
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
