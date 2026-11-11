<?php

declare(strict_types=1);

namespace Wurk\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Auto top-up outcomes and the monthly usage summary (Sprint 15.5b) — plain text, to the billing email. */
final class CreditsMail extends Mailable
{
    /** @param list<string> $lines */
    public function __construct(public readonly string $subjectLine, public readonly array $lines, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'billing::mail.credits');
    }
}
