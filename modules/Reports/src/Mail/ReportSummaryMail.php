<?php

declare(strict_types=1);

namespace Wurk\Reports\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The owners' weekly or monthly summary (Sprint 15.1b) — by email until the WhatsApp template is approved (decision D). */
final class ReportSummaryMail extends Mailable
{
    /**
     * @param  list<string>  $highlights
     * @param  list<array{title: string, lines: list<string>}>  $sections
     */
    public function __construct(public readonly string $business, public readonly string $span, public readonly array $highlights, public readonly array $sections, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->business}: your {$this->span}");
    }

    public function content(): Content
    {
        return new Content(text: 'reports::mail.summary');
    }
}
