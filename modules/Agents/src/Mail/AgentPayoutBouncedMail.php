<?php

declare(strict_types=1);

namespace Wurk\Agents\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A payout the bank couldn't deliver (Sprint 14.5b): the money is back in the agent's balance; check banking details. */
final class AgentPayoutBouncedMail extends Mailable
{
    public function __construct(public readonly string $name, public readonly int $cents, public readonly string $reference, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your WURK payout couldn’t be paid — please check your banking details');
    }

    public function content(): Content
    {
        return new Content(text: 'agents::mail.bounced');
    }
}
