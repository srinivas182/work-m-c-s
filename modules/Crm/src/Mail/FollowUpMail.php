<?php

declare(strict_types=1);

namespace Wurk\Crm\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Follow up today": the lead and the contact's name — never their number or email. */
final class FollowUpMail extends Mailable
{
    use Queueable;

    /** @param list<array{title: string, contact: string, url: string}> $leads */
    public function __construct(public string $business, public array $leads) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: count($this->leads) === 1 ? 'Follow up today: '.$this->leads[0]['title'] : 'Follow up today: '.count($this->leads).' leads');
    }

    public function content(): Content
    {
        return new Content(text: 'crm::mail.follow-up');
    }
}
