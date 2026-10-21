<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Identity\Events\PasswordChanged;
use Wurk\Identity\Exceptions\OtpThrottled;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Otp\OtpManager;
use Wurk\Identity\Support\Destination;
use Wurk\Identity\Support\PasswordPolicy;

/** Forgot password: verify a one-time code, then choose a new password. */
final class PasswordResetController
{
    public function __construct(private readonly OtpManager $otp) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Identity::ForgotPassword', ['identifier' => $request->session()->get('signin.identifier', '')]);
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255']]);
        $destination = Destination::parse($data['identifier']);

        try {
            $this->otp->request($destination, OtpManager::PURPOSE_RESET, $request->ip());
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['identifier' => "Please wait {$e->retryAfterSeconds} seconds before asking for another code."]);
        }

        $request->session()->put('reset.identifier', $destination->value);

        return redirect()->route('identity.forgot.code');
    }

    public function showCode(Request $request): Response|RedirectResponse
    {
        $identifier = $request->session()->get('reset.identifier');
        if (! is_string($identifier)) {
            return redirect()->route('identity.forgot');
        }

        return Inertia::render('Identity::Code', [
            'destination' => Destination::parse($identifier)->masked(),
            'purpose' => 'reset',
            'verifyUrl' => route('identity.forgot.verify'),
            'resendUrl' => route('identity.forgot.send'),
            'identifier' => $identifier,
            'resendAfter' => (int) config('wurk.identity.otp.resend_after_seconds'),
        ]);
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $identifier = $request->session()->get('reset.identifier');
        $identity = is_string($identifier) ? $this->otp->verify(Destination::parse($identifier), $data['code'], OtpManager::PURPOSE_RESET) : null;

        if ($identity === null) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right or has expired. Check it, or send a new one.']);
        }

        $request->session()->put(['reset.identity' => $identity->id, 'reset.verified_at' => now()->getTimestamp()]);

        return redirect()->route('identity.forgot.new');
    }

    public function showNew(Request $request): Response|RedirectResponse
    {
        return $this->verifiedIdentity($request) === null
            ? redirect()->route('identity.forgot')
            : Inertia::render('Identity::NewPassword');
    }

    public function reset(Request $request): RedirectResponse
    {
        $identity = $this->verifiedIdentity($request) ?? throw ValidationException::withMessages(['password' => 'Your reset expired. Please start again.']);
        $data = $request->validate(['password' => [...PasswordPolicy::rules(), 'confirmed']]);

        $identity->forceFill(['password' => $data['password']])->save();
        $request->session()->forget(['reset.identifier', 'reset.identity', 'reset.verified_at']);
        event(new PasswordChanged($identity));

        return redirect()->route('identity.sign-in')->with('status', 'Your password has been changed. Sign in with your new password.');
    }

    private function verifiedIdentity(Request $request): ?Identity
    {
        $id = $request->session()->get('reset.identity');
        $at = (int) $request->session()->get('reset.verified_at', 0);

        return is_string($id) && now()->getTimestamp() - $at < 600 ? Identity::query()->find($id) : null;
    }
}
