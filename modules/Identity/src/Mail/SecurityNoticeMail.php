<?php

declare(strict_types=1);

namespace Wurk\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Was this you?" (Sprint 18.2b): a new device signed in, the password changed, or the authenticator app was turned
 * on or off — with what to do if it wasn't them. Plain text; no links that sign anyone in.
 */
final class SecurityNoticeMail extends Mailable
{
    public const SUBJECTS = [
        'new_device' => 'New sign-in to your WURK account',
        'password' => 'Your WURK password was changed',
        'mfa_on' => 'Two-step sign-in was turned on for your WURK account',
        'mfa_off' => 'Two-step sign-in was turned off for your WURK account',
    ];

    public function __construct(public readonly string $kind, public readonly string $name, public readonly string $when, public readonly ?string $device,
        public readonly ?string $ip, public readonly string $securityUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUBJECTS[$this->kind] ?? 'Security notice for your WURK account');
    }

    public function content(): Content
    {
        return new Content(text: 'identity::mail.security-notice');
    }
}
