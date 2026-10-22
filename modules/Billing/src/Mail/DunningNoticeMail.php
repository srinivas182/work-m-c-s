<?php

declare(strict_types=1);

namespace Wurk\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DunningNoticeMail extends Mailable
{
    /** @param  'failed'|'reminder'|'suspended'  $kind */
    public function __construct(public readonly string $kind, public readonly string $business, public readonly string $payUrl, public readonly int $restrictDay, public readonly int $suspendDay) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'suspended' => "{$this->business} is suspended — pay to restore it",
            'reminder' => "Reminder: payment needed for {$this->business}",
            default => "We couldn’t take payment for {$this->business}",
        });
    }

    public function content(): Content
    {
        return new Content(text: 'billing::mail.dunning');
    }
}
