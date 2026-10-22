<?php

declare(strict_types=1);

namespace Wurk\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class CashApprovalCodeMail extends Mailable
{
    public function __construct(public readonly string $business, public readonly string $amount, public readonly string $receipt, public readonly string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Approve cash payment: {$this->amount} from {$this->business}");
    }

    public function content(): Content
    {
        return new Content(text: 'billing::mail.cash-code');
    }
}
