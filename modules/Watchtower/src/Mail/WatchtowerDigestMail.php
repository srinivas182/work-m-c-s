<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Wurk\Watchtower\Models\Incident;

/** The daily Watchtower digest (Sprint 18.0) — "All quiet" when it is, so HQ knows the watch is working. */
final class WatchtowerDigestMail extends Mailable
{
    /**
     * @param  list<Incident>  $new
     * @param  array<string, int>  $open  severity => count
     * @param  list<\stdClass>  $failing  checks failing now: name, message, failing_since
     */
    public function __construct(public readonly array $new, public readonly array $open, public readonly array $failing, public readonly int $resolved, public readonly string $link) {}

    public function quiet(): bool
    {
        return $this->new === [] && $this->failing === [] && array_sum($this->open) === 0;
    }

    public function envelope(): Envelope
    {
        $n = count($this->new);

        return new Envelope(subject: $this->quiet() ? '[WURK Watchtower] Daily report: all quiet'
            : "[WURK Watchtower] Daily report: {$n} new problem".($n === 1 ? '' : 's').', '.array_sum($this->open).' open');
    }

    public function content(): Content
    {
        return new Content(text: 'watchtower::mail.digest', with: ['quiet' => $this->quiet()]);
    }
}
