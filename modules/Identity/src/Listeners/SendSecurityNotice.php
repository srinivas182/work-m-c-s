<?php

declare(strict_types=1);

namespace Wurk\Identity\Listeners;

use Illuminate\Support\Facades\Mail;
use Throwable;
use Wurk\Identity\Events\MfaDisabled;
use Wurk\Identity\Events\MfaEnabled;
use Wurk\Identity\Events\NewDeviceSignedIn;
use Wurk\Identity\Events\PasswordChanged;
use Wurk\Identity\Mail\SecurityNoticeMail;

/**
 * Emails the person when something about their account's security changes (Sprint 18.2b, ASVS 2.2.3): a sign-in on
 * a new device, a password change, two-step sign-in turned on or off. People with only a phone number aren't emailed
 * (an SMS would cost a business credits for every sign-in); their devices are listed under Account → Security.
 */
final class SendSecurityNotice
{
    public function handle(NewDeviceSignedIn|PasswordChanged|MfaEnabled|MfaDisabled $event): void
    {
        $identity = $event->identity;
        if ($identity->email === null || $identity->email === '') {
            return;
        }
        $kind = match (true) {
            $event instanceof NewDeviceSignedIn => 'new_device',
            $event instanceof PasswordChanged => 'password',
            $event instanceof MfaEnabled => 'mfa_on',
            default => 'mfa_off',
        };
        try {
            Mail::to($identity->email)->send(new SecurityNoticeMail(
                $kind,
                $identity->first_name !== '' ? $identity->first_name : 'there',
                now('Africa/Johannesburg')->format('j M Y \a\t H:i'),
                $event instanceof NewDeviceSignedIn ? $event->device : null,
                $event instanceof NewDeviceSignedIn ? self::partialIp($event->ip) : null,
                'https://'.config('wurk.domains.app').'/account/security',
            ));
        } catch (Throwable $e) {
            report($e); // a notice that can't be sent must never block signing in
        }
    }

    /** "41.13.x.x" — enough to recognise a place, not a full address in someone's inbox. */
    public static function partialIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $p = explode('.', $ip);

            return "{$p[0]}.{$p[1]}.x.x";
        }

        return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
    }
}
