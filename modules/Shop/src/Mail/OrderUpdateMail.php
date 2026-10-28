<?php

declare(strict_types=1);

namespace Wurk\Shop\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To the customer: their order moved on. Fixed wording per step. */
final class OrderUpdateMail extends Mailable
{
    public const WORDING = [
        'paid' => ['Payment received', 'We’ve received your payment for order #:number. Thank you!'],
        'ready' => ['Ready for collection', 'Your order #:number is ready for you to collect.'],
        'out_for_delivery' => ['On its way', 'Your order #:number is on its way to you.'],
        'cancelled' => ['Order cancelled', 'Your order #:number has been cancelled. If you paid by card, your money is being refunded.'],
        'refunded' => ['Refund', 'We’ve refunded :amount for your order #:number.'],
    ];

    public function __construct(public readonly string $business, public readonly string $status, public readonly int $number, public readonly string $link, public readonly string $amount = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::WORDING[$this->status][0]." — order #{$this->number} · {$this->business}");
    }

    public function content(): Content
    {
        return new Content(text: 'shop::mail.order-update', with: ['message' => strtr(self::WORDING[$this->status][1], [':number' => (string) $this->number, ':amount' => $this->amount])]);
    }
}
