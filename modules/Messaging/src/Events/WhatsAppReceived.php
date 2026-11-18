<?php

declare(strict_types=1);

namespace Wurk\Messaging\Events;

use DateTimeImmutable;

/**
 * A customer's WhatsApp message to a business's own number (Sprint 21.2), dispatched inside that business's context.
 * Meta may deliver the same message more than once; `messageId` is unique, so listeners ignore repeats.
 */
final readonly class WhatsAppReceived
{
    /**
     * @param  string  $type  text | image | document | audio | video | sticker | location | contacts | interactive | button | reaction | unsupported
     * @param  array<string, mixed>  $extra  e.g. location (latitude, longitude, name), the button or list row tapped (id, title)
     */
    public function __construct(
        public string $tenantId,
        public string $from,            // the customer's number, digits only (WhatsApp ID)
        public ?string $name,           // the customer's WhatsApp profile name
        public string $messageId,
        public string $type,
        public string $text,            // the text, caption or tapped title ('' when none)
        public ?string $mediaId,
        public ?string $mime,
        public ?string $filename,
        public DateTimeImmutable $sentAt,
        public array $extra = [],
    ) {}
}
