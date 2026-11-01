<?php

declare(strict_types=1);

namespace Wurk\Messaging\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A worded customer message sent as plain email (test sends and the auto-reply). */
final class CustomerMessageMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $subjectLine, public readonly string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'messaging::mail.customer-message');
    }
}
