<?php

declare(strict_types=1);

namespace Wurk\Messaging\Events;

/**
 * A module sent a customer a message from the business's own WhatsApp number outside the inbox (a reminder, a payment
 * confirmation — Sprint 21.5), so Chats can show it in the customer's conversation. Dispatched inside the business.
 */
final readonly class WhatsAppSent
{
    public function __construct(public string $tenantId, public string $to, public string $text, public ?string $messageId) {}
}
