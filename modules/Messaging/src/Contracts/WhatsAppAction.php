<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/**
 * What happens when a customer taps a button WURK sent on WhatsApp (Sprint 21.5): a module that sends buttons with
 * payloads like "booking:cancel:01J…" handles their taps. It runs inside the business, for the customer whose
 * WhatsApp number tapped; it must check that number may act on the record.
 */
interface WhatsAppAction
{
    /** The payload's first part, e.g. "booking". */
    public function prefix(): string;

    /**
     * Act on a tap and say what the customer sees next, or null when the payload isn't one of this module's.
     *
     * @param  list<string>  $parts  the payload's other parts, split at ":"
     * @param  string  $from  the customer's WhatsApp number, digits only
     */
    public function handle(array $parts, string $from): ?WhatsAppAnswer;
}
