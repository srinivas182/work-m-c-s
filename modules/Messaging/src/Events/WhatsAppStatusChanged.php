<?php

declare(strict_types=1);

namespace Wurk\Messaging\Events;

/** A message the business sent was delivered, read or failed (Sprint 21.2), inside that business's context. */
final readonly class WhatsAppStatusChanged
{
    public function __construct(public string $tenantId, public string $messageId, public string $status, public ?string $error) {}
}
