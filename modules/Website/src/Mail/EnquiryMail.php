<?php

declare(strict_types=1);

namespace Wurk\Website\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class EnquiryMail extends Mailable
{
    /** @param  array<string, mixed>  $enquiry */
    public function __construct(public readonly string $business, public readonly array $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New quote request for {$this->business}");
    }

    public function content(): Content
    {
        return new Content(text: 'website::mail.enquiry');
    }
}
