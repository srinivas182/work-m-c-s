<?php

declare(strict_types=1);

namespace Wurk\Messaging\Events;

/** A message to WURK's own WhatsApp number (Sprint 23.5b: an owner's STOP to the Monday summary). Digits only for `from`. */
final readonly class WurkWhatsAppReceived
{
    public function __construct(public string $from, public string $text) {}
}
