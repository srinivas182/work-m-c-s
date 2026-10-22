<?php

declare(strict_types=1);

namespace Wurk\Billing\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Wurk\Billing\Models\Invoice;

final class InvoiceMail extends Mailable
{
    public function __construct(public readonly Invoice $invoice, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tax invoice {$this->invoice->number} from WURK");
    }

    public function content(): Content
    {
        return new Content(text: 'billing::mail.invoice', with: [
            'number' => $this->invoice->number,
            'total' => 'R'.number_format($this->invoice->total_cents / 100, 2, '.', ' '),
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->invoice->number.'.pdf')->withMime('application/pdf')];
    }
}
