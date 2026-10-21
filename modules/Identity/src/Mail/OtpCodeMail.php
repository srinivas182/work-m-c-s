<?php

declare(strict_types=1);

namespace Wurk\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OtpCodeMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly string $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your WURK code");
    }

    public function content(): Content
    {
        return new Content(markdown: 'identity::mail.otp', with: [
            'code' => $this->code,
            'reason' => $this->purpose === 'reset' ? 'reset your password' : 'sign in',
            'minutes' => intdiv((int) config('wurk.identity.otp.ttl_seconds', 300), 60),
        ]);
    }
}
