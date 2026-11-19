<?php

declare(strict_types=1);

namespace Wurk\Messaging\Events;

/**
 * A message to WURK's own WhatsApp number. Digits only for `from`.
 *
 * - 23.5b: an owner's STOP to the Monday summary.
 * - 25.6: the help line — a question, or a voice note (`mediaId`, with no text). `answered` is true when it was a reply
 *   to the owner's evening summary and has already been answered.
 */
final readonly class WurkWhatsAppReceived
{
    public function __construct(public string $from, public string $text, public ?string $mediaId = null, public ?string $mediaMime = null,
        public bool $answered = false, public string $messageId = '', public ?string $buttonId = null) {}
}
