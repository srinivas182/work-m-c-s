<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Wurk\Watchtower\Models\Incident;

/** One problem, emailed to WURK HQ (Sprint 18.0) — plain text, with the fix request ready to paste. */
final class IncidentMail extends Mailable
{
    public const REASONS = [
        'new' => 'New problem',
        'regression' => 'Back after a fix',
        'worse' => 'Getting more serious',
        'spike' => 'Happening much more often',
        'recovered' => 'Recovered',
    ];

    /** @param list<Incident> $during for an outage that's over: what else went wrong meanwhile */
    public function __construct(public readonly Incident $incident, public readonly string $reason, public readonly string $fixRequest, public readonly string $link,
        public readonly array $during = [], public readonly ?int $downMinutes = null) {}

    public function envelope(): Envelope
    {
        $tag = $this->reason === 'recovered' ? 'Recovered' : (Incident::SEVERITIES[$this->incident->severity] ?? 'Problem');

        return new Envelope(subject: mb_substr("[WURK Watchtower · {$tag}] ".(self::REASONS[$this->reason] ?? 'Problem').": {$this->incident->title}", 0, 200));
    }

    public function content(): Content
    {
        return new Content(text: 'watchtower::mail.incident');
    }
}
