<?php

declare(strict_types=1);

namespace Wurk\Website\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Someone asked, in the website chat, for a person to get back to them. */
final class ChatHandoffMail extends Mailable
{
    use Queueable;

    /** @param array{name: string, phone: string|null, email: string|null, message: string|null, transcript: list<array{from: string, text: string}>} $chat */
    public function __construct(public string $business, public array $chat) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Someone asked to talk to you in your website chat');
    }

    public function content(): Content
    {
        return new Content(text: 'website::mail.chat-handoff');
    }
}
