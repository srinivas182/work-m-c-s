<?php

declare(strict_types=1);

namespace Wurk\Bookings\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A booking message by email (free): the same fixed wording as SMS and WhatsApp. */
final class BookingMessageMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $text, public readonly ?string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'bookings::mail.message');
    }
}
