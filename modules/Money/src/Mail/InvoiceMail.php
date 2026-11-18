<?php

declare(strict_types=1);

namespace Wurk\Money\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An invoice, or a reminder of one, by email (23.5a): what's due and the link to view and pay it. */
final class InvoiceMail extends Mailable
{
    public function __construct(public readonly string $business, public readonly string $number, public readonly string $amount, public readonly string $due,
        public readonly string $link, public readonly bool $reminder) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reminder ? 'Reminder: ' : '')."{$this->business} — invoice {$this->number} for {$this->amount}");
    }

    public function content(): Content
    {
        return new Content(text: 'money::mail.invoice');
    }
}
