<?php

declare(strict_types=1);

namespace Wurk\Marketing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ContactMessageMail extends Mailable
{
    /** @param  array{name: string, contact: string, business: string|null, message: string}  $data */
    public function __construct(public readonly array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Website enquiry from '.$this->data['name']);
    }

    public function content(): Content
    {
        return new Content(text: 'marketing::contact-mail', with: $this->data);
    }
}
