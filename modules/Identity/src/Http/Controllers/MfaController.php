<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Identity\Auth\SignInFlow;
use Wurk\Identity\Events\MfaEnabled;
use Wurk\Identity\Events\SignInFailed;
use Wurk\Identity\Mfa\RecoveryCodes;
use Wurk\Identity\Mfa\Totp;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\IdentityMfa;
use Wurk\Identity\Support\Surface;

/**
 * Second step of sign-in: authenticator code (or recovery code), and the forced
 * enrolment for WURK staff who have not set up an authenticator yet.
 */
final class MfaController
{
    public function __construct(private readonly SignInFlow $flow) {}

    public function show(Request $request): Response|RedirectResponse
    {
        return $this->flow->pending($request) === null
            ? redirect()->route('identity.sign-in')
            : Inertia::render('Identity::MfaChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $identity = $this->flow->pending($request) ?? throw ValidationException::withMessages(['code' => 'Your sign-in expired. Please start again.']);
        $data = $request->validate(['code' => ['required', 'string', 'max:20'], 'recovery' => ['boolean']]);
        $mfa = $identity->mfa()->whereNotNull('confirmed_at')->first();

        $ok = $mfa !== null && (($data['recovery'] ?? false)
            ? RecoveryCodes::use($mfa, $data['code'])
            : Totp::verify($mfa->secret, $data['code'], $identity->id));

        if (! $ok) {
            event(new SignInFailed(hash('sha256', $identity->id), Surface::of($request), 'bad_mfa'));
            throw ValidationException::withMessages(['code' => 'That code didn’t work. Use the latest code from your authenticator app.']);
        }

        return $this->flow->complete($request);
    }

    public function setup(Request $request): Response|RedirectResponse
    {
        $identity = $this->flow->pending($request);
        if ($identity === null) {
            return redirect()->route('identity.sign-in');
        }

        return Inertia::render('Identity::MfaSetup', $this->enrolment($request, $identity));
    }

    public function confirmSetup(Request $request): Response
    {
        $identity = $this->flow->pending($request) ?? throw ValidationException::withMessages(['code' => 'Your sign-in expired. Please start again.']);
        $codes = $this->confirm($request, $identity);

        return Inertia::render('Identity::RecoveryCodes', ['codes' => $codes, 'continueUrl' => route('identity.mfa.continue')]);
    }

    public function continueAfterSetup(Request $request): RedirectResponse
    {
        return $this->flow->complete($request);
    }

    /**
     * Shared with account settings: creates (or reuses) an unconfirmed secret.
     *
     * @return array{qr: string, secret: string, account: string}
     */
    public static function enrolment(Request $request, Identity $identity): array
    {
        $mfa = IdentityMfa::query()->firstOrNew(['identity_id' => $identity->id]);
        if (! $mfa->exists || $mfa->confirmed_at === null && ! $request->session()->has('mfa.enrolling')) {
            $mfa->fill(['secret' => Totp::generateSecret(), 'type' => 'totp', 'confirmed_at' => null])->save();
            $request->session()->put('mfa.enrolling', true);
        }

        $account = $identity->email ?? $identity->phone ?? $identity->id;

        return ['qr' => Totp::qrSvg(Totp::uri($mfa->secret, $account)), 'secret' => trim(chunk_split($mfa->secret, 4, ' ')), 'account' => $account];
    }

    /** @return list<string> plain recovery codes, shown once */
    public static function confirm(Request $request, Identity $identity): array
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $mfa = IdentityMfa::query()->find($identity->id);

        if ($mfa === null || $mfa->confirmed_at !== null || ! Totp::verify($mfa->secret, $data['code'], $identity->id)) {
            throw ValidationException::withMessages(['code' => 'That code didn’t match. Scan the QR code again and enter the current 6-digit code.']);
        }

        $codes = RecoveryCodes::generate();
        $mfa->forceFill(['confirmed_at' => now(), 'recovery_codes' => $codes['hashed']])->save();
        $request->session()->forget('mfa.enrolling');
        event(new MfaEnabled($identity));

        return $codes['plain'];
    }
}
