<?php

declare(strict_types=1);

namespace Wurk\Data\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Your data" notices (Sprint 16.6) — plain text. */
final class DataMail extends Mailable
{
    /** @param list<string> $lines */
    public function __construct(public readonly string $subjectLine, public readonly array $lines, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'data::mail.notice');
    }
}
