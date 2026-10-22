<?php

declare(strict_types=1);

namespace Wurk\Billing\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Wurk\Billing\Models\CreditNote;

final class CreditNoteMail extends Mailable
{
    public function __construct(public readonly CreditNote $note, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Credit note {$this->note->number} from WURK");
    }

    public function content(): Content
    {
        return new Content(text: 'billing::mail.credit-note', with: ['number' => $this->note->number, 'total' => 'R'.number_format($this->note->total_cents / 100, 2, '.', ' ')]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->note->number.'.pdf')->withMime('application/pdf')];
    }
}
