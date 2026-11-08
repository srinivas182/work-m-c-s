<?php

declare(strict_types=1);

namespace Wurk\Agents\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Remittance advice (Sprint 14.5b): what WURK paid an agent, the bank reference, and the businesses it covers. */
final class AgentRemittanceMail extends Mailable
{
    /** @param list<array{business: string, cents: int}> $covers */
    public function __construct(public readonly string $name, public readonly int $cents, public readonly string $reference, public readonly string $bank, public readonly array $covers) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'WURK paid you R'.number_format($this->cents / 100, 2).' — '.$this->reference);
    }

    public function content(): Content
    {
        return new Content(text: 'agents::mail.remittance');
    }
}
