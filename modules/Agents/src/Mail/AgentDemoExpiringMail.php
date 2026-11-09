<?php

declare(strict_types=1);

namespace Wurk\Agents\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An agent's demo site is about to be archived (Sprint 14.8a; days 10 and 13). */
final class AgentDemoExpiringMail extends Mailable
{
    public function __construct(public readonly string $name, public readonly string $business, public readonly int $daysLeft, public readonly string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your {$this->business} demo closes in {$this->daysLeft} ".($this->daysLeft === 1 ? 'day' : 'days'));
    }

    public function content(): Content
    {
        return new Content(text: 'agents::mail.demo-expiring');
    }
}
