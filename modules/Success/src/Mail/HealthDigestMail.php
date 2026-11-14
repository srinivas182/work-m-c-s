<?php

declare(strict_types=1);

namespace Wurk\Success\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The weekly business-health digest (Sprint 16.5) — plain text. */
final class HealthDigestMail extends Mailable
{
    /** @param list<string> $lines */
    public function __construct(public readonly string $subjectLine, public readonly array $lines, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'success::mail.digest');
    }
}
