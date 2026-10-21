<?php

declare(strict_types=1);

namespace Wurk\Identity\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Wurk\Identity\Events\SignedIn;
use Wurk\Identity\Events\SignInFailed;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Support\Surface;

/**
 * Turns a verified first factor (code or password) into a signed-in session.
 *
 * Nobody is signed in until every required factor passes: while MFA is
 * outstanding, only a short-lived "pending" marker sits in the session.
 */
final readonly class SignInFlow
{
    private const PENDING = 'signin.pending';

    private const PENDING_TTL = 600;

    public function __construct(private SessionRegistry $sessions, private Dispatcher $events) {}

    public function afterFirstFactor(Request $request, Identity $identity, bool $remember, string $method): RedirectResponse
    {
        $surface = Surface::of($request);

        if ($surface === Surface::ADMIN && ! $identity->isPlatformStaff()) {
            $this->events->dispatch(new SignInFailed(hash('sha256', $identity->id), $surface, 'not_staff'));
            throw ValidationException::withMessages(['identifier' => 'This account doesn’t have WURK staff access.']);
        }

        $request->session()->put(self::PENDING, [
            'identity' => $identity->id, 'remember' => $remember, 'method' => $method, 'at' => now()->getTimestamp(),
        ]);

        if ($identity->hasMfa()) {
            return redirect()->route('identity.mfa');
        }

        if ($surface === Surface::ADMIN) {
            return redirect()->route('identity.mfa.setup'); // staff must enrol before first access
        }

        return $this->complete($request);
    }

    public function pending(Request $request): ?Identity
    {
        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || now()->getTimestamp() - (int) $pending['at'] > self::PENDING_TTL) {
            $request->session()->forget(self::PENDING);

            return null;
        }

        $identity = Identity::query()->find((string) $pending['identity']);

        return $identity?->isActive() ? $identity : null;
    }

    public function complete(Request $request): RedirectResponse
    {
        $identity = $this->pending($request);
        abort_if($identity === null, 419, 'Your sign-in expired. Please start again.');

        /** @var array{remember: bool, method: string} $pending */
        $pending = $request->session()->pull(self::PENDING);
        $surface = Surface::of($request);
        $remember = $pending['remember'] && Surface::sessionPolicy($surface)['remember'];

        // Set per sign-in (not at boot): guards are rebuilt for every request under
        // Octane. Remember-me lasts 30 days instead of Laravel's five-year default.
        $guard = Auth::guard('web');
        if ($guard instanceof SessionGuard) {
            $guard->setRememberDuration(60 * 24 * 30);
        }
        $guard->login($identity, $remember);
        $request->session()->regenerate();
        $request->session()->put([
            'auth.started_at' => now()->getTimestamp(),
            'auth.last_seen' => now()->getTimestamp(),
            'auth.remember' => $remember,
        ]);

        $this->sessions->start($request, $identity, $surface);
        $identity->forceFill(['last_login_at' => now()])->save();
        $this->events->dispatch(new SignedIn($identity, $surface, $pending['method']));

        return redirect()->intended(self::home($surface));
    }

    public static function home(string $surface): string
    {
        return $surface === Surface::ADMIN ? route('admin.home') : route('identity.businesses');
    }
}
