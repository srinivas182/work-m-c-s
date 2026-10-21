<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Wurk\Identity\Auth\SessionRegistry;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Support\Surface;

/**
 * Enforces idle and absolute session limits per surface (spec §7.3), honours
 * "sign out other devices", and keeps the device list up to date.
 */
final readonly class SessionGuardian
{
    public function __construct(private SessionRegistry $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = Auth::user();

        if (! $identity instanceof Identity) {
            return $next($request);
        }

        $surface = Surface::of($request);
        $policy = Surface::sessionPolicy($surface);
        $session = $request->session();
        $now = now()->getTimestamp();

        if (! $session->has('auth.started_at')) {
            // Signed back in by a remember-me cookie: this is a new device session.
            $session->put(['auth.started_at' => $now, 'auth.last_seen' => $now, 'auth.remember' => true]);
            $this->sessions->start($request, $identity, $surface);
        }

        $record = $this->sessions->current($request);
        $idleExpired = ! $session->get('auth.remember') && $now - (int) $session->get('auth.last_seen') > $policy['idle'] * 60;
        $absoluteExpired = $now - (int) $session->get('auth.started_at') > $policy['absolute'] * 60;

        if ($record?->revoked_at !== null || $idleExpired || $absoluteExpired) {
            return $this->signOut($request, $record?->revoked_at !== null
                ? 'You were signed out from another device.'
                : 'For your security you were signed out after a period of inactivity.');
        }

        $session->put('auth.last_seen', $now);
        if ($record !== null) {
            $this->sessions->touch($record);
        } else {
            $this->sessions->start($request, $identity, $surface);
        }

        return $next($request);
    }

    private function signOut(Request $request, string $message): Response
    {
        $this->sessions->end($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('identity.sign-in')->with('status', $message);
    }
}
