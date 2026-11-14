<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Identity\Exceptions\OtpThrottled;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Otp\OtpManager;
use Wurk\Identity\Support\Destination;

/** "Confirm it's you" (Sprint 18.2b): the password, or a code sent to the person's email or phone. */
final class ConfirmController
{
    public const PURPOSE = 'confirm';

    public const MINUTES = 15;

    public function __construct(private readonly OtpManager $otp) {}

    /** Signed in (with a password or code — not a remembered device) or confirmed in the last 15 minutes. */
    public static function recent(Request $request): bool
    {
        $session = $request->session();
        $at = max((int) $session->get('auth.confirmed_at', 0), (int) $session->get('auth.signed_in_at', 0));

        return $at > 0 && now()->getTimestamp() - $at <= self::MINUTES * 60;
    }

    public function show(Request $request): Response
    {
        $identity = $this->identity($request);
        $destination = $this->destination($identity);

        return Inertia::render('Identity::Confirm', [
            'to' => self::safe((string) $request->query('to', '/')),
            'hasPassword' => $identity->password !== null,
            'destination' => $destination?->masked(),
            'codeSent' => (bool) $request->session()->get('confirm.code_sent', false),
        ]);
    }

    public function password(Request $request): RedirectResponse
    {
        $identity = $this->identity($request);
        $data = $request->validate(['password' => ['required', 'string', 'max:128'], 'to' => ['nullable', 'string', 'max:500']]);
        if ($identity->password === null || ! Hash::check($data['password'], $identity->password)) {
            throw ValidationException::withMessages(['password' => 'That password isn’t right.']);
        }

        return $this->confirmed($request, (string) ($data['to'] ?? '/'));
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $destination = $this->destination($this->identity($request)) ?? abort(422);
        try {
            $this->otp->request($destination, self::PURPOSE, $request->ip());
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['code' => $e->retryAfterSeconds > 120
                ? 'Too many codes requested. Please try again in '.(int) ceil($e->retryAfterSeconds / 60).' minutes.'
                : "Please wait {$e->retryAfterSeconds} seconds before asking for another code."]);
        }
        $request->session()->put('confirm.code_sent', true);

        return back()->with('status', 'We sent you a code.');
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $destination = $this->destination($this->identity($request)) ?? abort(422);
        $data = $request->validate(['code' => ['required', 'string', 'max:10'], 'to' => ['nullable', 'string', 'max:500']]);
        if (! $this->otp->confirm($destination, trim($data['code']), self::PURPOSE)) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right, or it has expired.']);
        }

        return $this->confirmed($request, (string) ($data['to'] ?? '/'));
    }

    private function confirmed(Request $request, string $to): RedirectResponse
    {
        $request->session()->put('auth.confirmed_at', now()->getTimestamp());
        $request->session()->forget('confirm.code_sent');

        return redirect(self::safe($to))->with('status', 'Thanks — you’re confirmed for the next '.self::MINUTES.' minutes.');
    }

    /** Only a path on this site: never somewhere else. */
    public static function safe(string $to): string
    {
        return str_starts_with($to, '/') && ! str_starts_with($to, '//') && ! str_contains($to, '\\') ? $to : '/';
    }

    private function destination(Identity $identity): ?Destination
    {
        $value = $identity->email ?? $identity->phone;

        return $value === null || $value === '' ? null : Destination::parse($value);
    }

    private function identity(Request $request): Identity
    {
        $identity = $request->user();
        abort_unless($identity instanceof Identity, 401);

        return $identity;
    }
}
