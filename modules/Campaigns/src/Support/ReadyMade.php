<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Support;

/** Ready-made campaigns with suggested wording (Sprint 15.3a — recommended addition). */
final class ReadyMade
{
    /** @return array<string, array{name: string, channel: string, segment: string, subject: string, body: string, about: string}> */
    public static function all(): array
    {
        return [
            'win-back' => ['name' => 'We miss you', 'channel' => 'email', 'segment' => 'inactive', 'subject' => 'We miss you, {first_name}',
                'body' => "Hi {first_name},\n\nIt’s been a while since we saw you — we’d love to welcome you back. Book or order online any time:\n\n(add your website link here)\n\nSee you soon!",
                'about' => 'Customers you haven’t seen in 60 days or more.'],
            'thank-you' => ['name' => 'Thank you', 'channel' => 'sms', 'segment' => 'recent', 'subject' => '',
                'body' => 'Hi {first_name}, thank you for choosing us! We hope to see you again soon.',
                'about' => 'Customers you’ve seen in the last 30 days.'],
        ];
    }
}
