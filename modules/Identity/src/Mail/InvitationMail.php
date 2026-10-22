<?php

declare(strict_types=1);

namespace Wurk\Identity\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $joining,
        public readonly string $role,
        public readonly string $url,
        public readonly ?string $firstName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You’re invited to join {$this->joining} on WURK");
    }

    public function content(): Content
    {
        return new Content(markdown: 'identity::mail.invitation', with: [
            'joining' => $this->joining,
            'role' => str_replace('-', ' ', $this->role),
            'url' => $this->url,
            'greeting' => $this->firstName ? "Hi {$this->firstName}," : 'Hi,',
        ]);
    }
}
