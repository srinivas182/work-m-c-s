<?php

declare(strict_types=1);

namespace Wurk\Support\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Ticket news, both ways (Sprint 16.3) — plain text, with a link to the conversation. */
final class TicketMail extends Mailable
{
    /** @param list<string> $lines */
    public function __construct(public readonly string $subjectLine, public readonly array $lines, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'support::mail.ticket');
    }
}
