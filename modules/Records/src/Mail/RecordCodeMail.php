<?php

declare(strict_types=1);

namespace Wurk\Records\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the patient: a one-time code (10 minutes). */
final class RecordCodeMail extends Mailable
{
    public function __construct(public readonly string $business, public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your code: {$this->code}");
    }

    public function content(): Content
    {
        return new Content(text: 'records::mail.code');
    }
}
