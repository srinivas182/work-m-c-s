Hi {!! $name !!},

@switch($kind)
@case('new_device')
Your WURK account was signed in to on {!! $device !!} on {{ $when }} (South African time){!! $ip ? ' from internet address '.$ip : '' !!}. It's a device you haven't used with WURK before.
@break
@case('password')
The password for your WURK account was changed on {{ $when }} (South African time). Your other devices were signed out.
@break
@case('mfa_on')
Two-step sign-in (an authenticator app) was turned on for your WURK account on {{ $when }} (South African time).
@break
@case('mfa_off')
Two-step sign-in (an authenticator app) was turned off for your WURK account on {{ $when }} (South African time). Signing in now needs only your password or a code.
@break
@endswitch

If this was you, there's nothing to do.

If it wasn't you:
1. Sign in and change your password: {!! $securityUrl !!}
2. On the same page, choose "Sign out other devices".
3. Turn on two-step sign-in if it's off.
4. Tell us at {!! config('wurk.support_email') !!} — we'll help.

WURK will never ask for your password or a code by email, SMS or phone.

WURK
