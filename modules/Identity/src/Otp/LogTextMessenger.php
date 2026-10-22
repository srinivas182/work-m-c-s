<?php

declare(strict_types=1);

namespace Wurk\Identity\Otp;

use Illuminate\Support\Facades\Log;
use Wurk\Identity\Contracts\TextMessenger;

final class LogTextMessenger implements TextMessenger
{
    public function send(string $to, string $text, string $purpose): bool
    {
        Log::info("[text → {$to}] {$text}");

        return true;
    }
}
