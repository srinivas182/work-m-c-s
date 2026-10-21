<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Identity\Auth\SignInFlow;
use Wurk\Identity\Events\SignInFailed;
use Wurk\Identity\Exceptions\OtpThrottled;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Otp\OtpManager;
use Wurk\Identity\Support\Destination;
use Wurk\Identity\Support\Surface;

final class SignInController
{
    public function __construct(private readonly OtpManager $otp, private readonly SignInFlow $flow) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Identity::SignIn', [
            'surface' => Surface::of($request),
            'identifier' => $request->session()->get('signin.identifier', ''),
        ]);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255'], 'remember' => ['boolean']]);
        $destination = Destination::parse($data['identifier']);

        $this->issue($destination, OtpManager::PURPOSE_LOGIN, $request);
        // A resend posts only the identifier, so keep the earlier remember-me choice.
        $remember = array_key_exists('remember', $data) ? (bool) $data['remember'] : (bool) $request->session()->get('signin.remember', false);
        $request->session()->put(['signin.identifier' => $destination->value, 'signin.remember' => $remember]);

        return redirect()->route('identity.code');
    }

    public function showCode(Request $request): Response|RedirectResponse
    {
        $identifier = $request->session()->get('signin.identifier');
        if (! is_string($identifier)) {
            return redirect()->route('identity.sign-in');
        }

        return Inertia::render('Identity::Code', [
            'destination' => Destination::parse($identifier)->masked(),
            'purpose' => 'login',
            'verifyUrl' => route('identity.code.verify'),
            'resendUrl' => route('identity.code.send'),
            'identifier' => $identifier,
            'resendAfter' => (int) config('wurk.identity.otp.resend_after_seconds'),
        ]);
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $identifier = $request->session()->get('signin.identifier');
        if (! is_string($identifier)) {
            return redirect()->route('identity.sign-in');
        }

        $destination = Destination::parse($identifier);
        $identity = $this->otp->verify($destination, $data['code'], OtpManager::PURPOSE_LOGIN);

        if ($identity === null) {
            event(new SignInFailed($destination->hash(), Surface::of($request), 'bad_code'));
            throw ValidationException::withMessages(['code' => 'That code isn’t right or has expired. Check it, or send a new one.']);
        }

        return $this->flow->afterFirstFactor($request, $identity, (bool) $request->session()->pull('signin.remember', false), 'code');
    }

    public function showPassword(Request $request): Response
    {
        return Inertia::render('Identity::Password', [
            'identifier' => $request->session()->get('signin.identifier', ''),
            'surface' => Surface::of($request),
        ]);
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
            'remember' => ['boolean'],
        ]);
        $destination = Destination::parse($data['identifier']);
        $key = 'pwd:'.$destination->hash().'|'.$request->ip();
        $max = (int) config('wurk.identity.password.max_attempts', 5);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['password' => "Too many attempts. Try again in {$minutes} minutes, or sign in with a code."]);
        }

        $identity = Identity::query()->where($destination->column(), $destination->value)->first();
        $valid = $identity !== null && $identity->password !== null && $identity->isActive()
            && Hash::check($data['password'], $identity->password);

        if (! $valid) {
            RateLimiter::hit($key, (int) config('wurk.identity.password.lockout_seconds', 900));
            event(new SignInFailed($destination->hash(), Surface::of($request), 'bad_password'));
            throw ValidationException::withMessages(['password' => 'That email/number and password don’t match.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('signin.identifier', $destination->value);

        return $this->flow->afterFirstFactor($request, $identity, (bool) ($data['remember'] ?? false), 'password');
    }

    private function issue(Destination $destination, string $purpose, Request $request): void
    {
        try {
            $this->otp->request($destination, $purpose, $request->ip());
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['identifier' => $e->retryAfterSeconds > 120
                ? 'Too many codes requested. Please try again in '.(int) ceil($e->retryAfterSeconds / 60).' minutes.'
                : "Please wait {$e->retryAfterSeconds} seconds before asking for another code."]);
        }
    }
}
