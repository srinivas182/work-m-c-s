<?php

declare(strict_types=1);

namespace Wurk\Messaging\Otp;

use Wurk\Identity\Contracts\TextMessenger;
use Wurk\Messaging\Contracts\Messenger;

/** Identity's plain-text messages (e.g. invitations by mobile) through the active SMS provider. */
final readonly class SmsTextMessenger implements TextMessenger
{
    public function __construct(private Messenger $messenger) {}

    public function send(string $to, string $text, string $purpose): bool
    {
        return $this->messenger->sms($to, $text, $purpose)->ok;
    }
}
