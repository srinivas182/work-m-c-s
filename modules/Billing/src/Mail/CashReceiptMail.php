<?php

declare(strict_types=1);

namespace Wurk\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Payment receipt for cash; the tax invoice follows from invoicing (Sprint 3.4). */
final class CashReceiptMail extends Mailable
{
    public function __construct(
        public readonly string $business,
        public readonly string $receiptNumber,
        public readonly string $payer,
        public readonly int $totalCents,
        public readonly int $vatCents,
        public readonly string $purpose,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Payment received — {$this->business} ({$this->receiptNumber})");
    }

    public function content(): Content
    {
        return new Content(text: 'billing::mail.cash-receipt', with: [
            'total' => 'R'.number_format($this->totalCents / 100, 2, '.', ' '),
            'vat' => 'R'.number_format($this->vatCents / 100, 2, '.', ' '),
        ]);
    }
}
