<?php

declare(strict_types=1);

namespace Wurk\Shop\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the people who manage the shop: a new order came in. */
final class NewOrderMail extends Mailable
{
    /** @param  list<string>  $lines */
    public function __construct(public readonly int $number, public readonly string $customer, public readonly array $lines, public readonly string $total, public readonly string $how, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New order #{$this->number} — {$this->total}");
    }

    public function content(): Content
    {
        return new Content(text: 'shop::mail.new-order');
    }
}
