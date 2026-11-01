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

    /**
     * The order messages for Settings → Messages (email only): today's wording is the standard.
     *
     * @return array<string, array{group: string, label: string, placeholders: list<string>, required: list<string>, subject: string, text: string, sample: array<string, string>, channels: list<string>}>
     */
    public static function definitions(): array
    {
        $sample = ['business' => 'Kasi Threads', 'number' => '1042', 'amount' => 'R250.00'];
        $out = ['order.placed' => ['group' => 'Orders', 'label' => 'Order placed', 'placeholders' => ['business', 'number'], 'required' => [],
            'subject' => 'Your order #{number} — {business}', 'text' => 'Thank you for your order with {business}.', 'sample' => $sample, 'channels' => ['email']]];
        foreach (self::WORDING as $status => [$label, $text]) {
            $out['order.'.$status] = ['group' => 'Orders', 'label' => 'Order: '.mb_strtolower($label), 'placeholders' => $status === 'refunded' ? ['business', 'number', 'amount'] : ['business', 'number'],
                'required' => [], 'subject' => $label.' — order #{number} · {business}', 'text' => strtr($text, [':number' => '{number}', ':amount' => '{amount}']), 'sample' => $sample, 'channels' => ['email']];
        }

        return $out;
    }

    /** The subject and message are worded by the sender (the business's wording, else the standard). */
    public function __construct(public readonly string $business, public readonly string $subjectLine, public readonly string $message, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'shop::mail.order-update', with: ['message' => $this->message]);
    }
}
