<?php

declare(strict_types=1);

namespace Wurk\Identity\Otp;

use Illuminate\Support\Facades\Mail;
use Wurk\Identity\Contracts\OtpChannel;
use Wurk\Identity\Mail\OtpCodeMail;

final class MailOtpChannel implements OtpChannel
{
    public function name(): string
    {
        return 'email';
    }

    public function send(string $destination, string $code, string $purpose): void
    {
        Mail::to($destination)->send(new OtpCodeMail($code, $purpose));
    }
}
