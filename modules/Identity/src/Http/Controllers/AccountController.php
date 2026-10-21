<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Identity\Auth\SessionRegistry;
use Wurk\Identity\Events\MfaDisabled;
use Wurk\Identity\Events\PasswordChanged;
use Wurk\Identity\Events\SessionsRevoked;
use Wurk\Identity\Events\SignedOut;
use Wurk\Identity\Mfa\Totp;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\IdentitySession;
use Wurk\Identity\Support\PasswordPolicy;
use Wurk\Identity\Support\Surface;

final class AccountController
{
    public function __construct(private readonly SessionRegistry $sessions) {}

    public function show(Request $request): Response
    {
        $identity = $this->identity($request);
        $current = $this->sessions->currentKey($request);

        return Inertia::render('Identity::Security', [
            'profile' => [
                'first_name' => $identity->first_name,
                'last_name' => $identity->last_name,
                'email' => $identity->email,
                'phone' => $identity->phone,
            ],
            'hasPassword' => $identity->password !== null,
            'mfaEnabled' => $identity->hasMfa(),
            'mfaRequired' => Surface::of($request) === Surface::ADMIN || $identity->isPlatformStaff(),
            'enrolment' => $request->session()->has('mfa.enrolling') && ! $identity->hasMfa()
                ? MfaController::enrolment($request, $identity) : null,
            'recoveryCodes' => $request->session()->get('mfa.recovery_codes'),
            'sessions' => IdentitySession::query()->where('identity_id', $identity->id)->whereNull('revoked_at')
                ->orderByDesc('last_active_at')->get()
                ->map(fn (IdentitySession $s) => [
                    'id' => $s->id,
                    'surface' => $s->surface,
                    'device' => self::describe((string) $s->user_agent),
                    'ip' => $s->ip,
                    'last_active' => $s->id === $current ? 'Active now' : $s->last_active_at->diffForHumans(),
                    'current' => $s->id === $current,
                ])->all(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $identity = $this->identity($request);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
        ]);
        $identity->update(['first_name' => $data['first_name'], 'last_name' => $data['last_name'] ?? '']);

        return back()->with('status', 'Profile saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $identity = $this->identity($request);
        $data = $request->validate([
            'current_password' => [Rule::requiredIf($identity->password !== null), 'nullable', 'string'],
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
        ]);

        if ($identity->password !== null && ! Hash::check((string) $data['current_password'], $identity->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password isn’t right.']);
        }

        $identity->forceFill(['password' => $data['password']])->save();
        $this->sessions->revokeOthers($identity, $request);
        event(new PasswordChanged($identity));

        return back()->with('status', 'Password changed. Other devices have been signed out.');
    }

    public function beginMfa(Request $request): RedirectResponse
    {
        abort_if($this->identity($request)->hasMfa(), 409);
        $request->session()->forget('mfa.enrolling');
        MfaController::enrolment($request, $this->identity($request));

        return back();
    }

    public function confirmMfa(Request $request): RedirectResponse
    {
        $codes = MfaController::confirm($request, $this->identity($request));

        return back()->with('status', 'Authenticator app turned on.')->with('mfa.recovery_codes', $codes);
    }

    public function disableMfa(Request $request): RedirectResponse
    {
        $identity = $this->identity($request);
        if ($identity->isPlatformStaff()) {
            throw ValidationException::withMessages(['mfa' => 'WURK staff accounts must keep an authenticator app.']);
        }
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $mfa = $identity->mfa()->first();

        if ($mfa === null || ! Totp::verify($mfa->secret, $data['code'], $identity->id)) {
            throw ValidationException::withMessages(['code' => 'Enter the current code from your authenticator app to turn it off.']);
        }

        $mfa->delete();
        event(new MfaDisabled($identity));

        return back()->with('status', 'Authenticator app turned off.');
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $identity = $this->identity($request);
        $count = $this->sessions->revokeOthers($identity, $request);
        event(new SessionsRevoked($identity, $count));

        return back()->with('status', $count === 1 ? 'Signed out of 1 other device.' : "Signed out of {$count} other devices.");
    }

    public function signOut(Request $request): RedirectResponse
    {
        $identity = $this->identity($request);
        $this->sessions->end($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        event(new SignedOut($identity));

        return redirect()->route('identity.sign-in')->with('status', 'You’re signed out.');
    }

    private function identity(Request $request): Identity
    {
        $identity = $request->user();
        abort_unless($identity instanceof Identity, 401);

        return $identity;
    }

    private static function describe(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };

        return "{$browser} on {$os}";
    }
}
