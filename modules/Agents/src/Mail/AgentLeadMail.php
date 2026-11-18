<?php

declare(strict_types=1);

namespace Wurk\Agents\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A visitor who followed an agent's link asked, in WURK's sales chat, for the agent to contact them (Sprint 19.5). */
final class AgentLeadMail extends Mailable
{
    public function __construct(public readonly string $agent, public readonly string $visitor, public readonly string $contact, public readonly string $note) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->visitor} would like you to contact them about WURK");
    }

    public function content(): Content
    {
        return new Content(text: 'agents::mail.lead');
    }
}
