<?php

declare(strict_types=1);

namespace Wurk\Records\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the patient: their private link (no health details in the email). */
final class RecordLinkMail extends Mailable
{
    public function __construct(public readonly string $business, public readonly string $patient, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your documents from {$this->business}");
    }

    public function content(): Content
    {
        return new Content(text: 'records::mail.link');
    }
}
