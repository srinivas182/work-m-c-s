<?php

declare(strict_types=1);

namespace Wurk\Shop\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the customer: their order, how to pay, and the link to their order page. */
final class OrderPlacedMail extends Mailable
{
    /** @param  list<string>  $lines */
    public function __construct(public readonly string $business, public readonly string $subjectLine, public readonly string $intro, public readonly int $number, public readonly array $lines, public readonly string $total, public readonly ?string $howToPay, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'shop::mail.order-placed');
    }
}
