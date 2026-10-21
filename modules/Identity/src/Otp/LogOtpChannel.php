<?php

declare(strict_types=1);

namespace Wurk\Identity\Otp;

use Illuminate\Support\Facades\Log;
use Wurk\Identity\Contracts\OtpChannel;

/**
 * Development and testing only: writes the code to the log instead of sending
 * an SMS. Never configured in production (Sprint 2 provides real drivers).
 */
final class LogOtpChannel implements OtpChannel
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $destination, string $code, string $purpose): void
    {
        Log::info("One-time code for {$destination} ({$purpose}): {$code}");
    }
}
