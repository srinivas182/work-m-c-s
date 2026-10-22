<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/** Sends through whichever provider WURK staff activated for the channel, and records the delivery. */
interface Messenger
{
    public function sms(string $to, string $text, string $purpose): SendResult;

    /** @param  list<string>  $parameters  template body parameters, in order */
    public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult;

    public function channelActive(string $channel): bool;
}
