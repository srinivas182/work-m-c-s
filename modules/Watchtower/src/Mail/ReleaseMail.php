<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sprint 17.2: "WURK v1.0.0 is live on production" — or rolled back, or failed, with what happened. */
final class ReleaseMail extends Mailable
{
    public function __construct(public readonly string $version, public readonly string $environment, public readonly string $status, public readonly ?string $note) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->status) {
            'live' => "[WURK] {$this->version} is live on {$this->environment}",
            'rolled_back' => "[WURK] {$this->version} was rolled back on {$this->environment}",
            default => "[WURK] {$this->version} failed to deploy to {$this->environment}",
        });
    }

    public function content(): Content
    {
        return new Content(text: 'watchtower::mail.release');
    }
}
