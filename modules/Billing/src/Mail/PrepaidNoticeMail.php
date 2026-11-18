<?php

declare(strict_types=1);

namespace Wurk\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Pay as you go (Sprint 22.5): credits running low (N days left), or used up (the grace period has started). */
final class PrepaidNoticeMail extends Mailable
{
    /** @param  'low'|'empty'  $kind */
    public function __construct(public readonly string $kind, public readonly string $business, public readonly int $days, public readonly string $topUpUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === 'empty'
            ? "{$this->business}: your WURK credits have run out"
            : "{$this->business}: about {$this->days} ".($this->days === 1 ? 'day' : 'days').' of WURK left');
    }

    public function content(): Content
    {
        return new Content(text: 'billing::mail.prepaid');
    }
}
