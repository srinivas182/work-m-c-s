<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** A campaign email (Sprint 15.3a): HTML (with the open image — decision C) and plain text; always with the opt-out. */
final class CampaignMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $text, public readonly string $htmlBody, public readonly string $business, public readonly string $stopUrl, public readonly string $openUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(html: 'campaigns::mail.html', text: 'campaigns::mail.text');
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->stopUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }
}
