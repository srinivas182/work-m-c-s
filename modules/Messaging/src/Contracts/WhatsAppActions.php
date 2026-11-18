<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/** Modules register what their WhatsApp buttons do; the inbox asks here when a customer taps one (21.5). */
interface WhatsAppActions
{
    /** @param  class-string<WhatsAppAction>  $action */
    public function register(string $action): void;

    /** The answer to a tapped button's payload, or null when no module handles it. */
    public function handle(string $payload, string $from): ?WhatsAppAnswer;
}
