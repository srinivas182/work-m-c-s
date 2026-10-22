<?php

declare(strict_types=1);

namespace Wurk\Identity\Contracts;

/**
 * Plain-text messages to a mobile number (e.g. invitations). The Messaging
 * module provides the real implementation; by default messages go to the log.
 */
interface TextMessenger
{
    public function send(string $to, string $text, string $purpose): bool;
}
