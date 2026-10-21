<?php

declare(strict_types=1);

namespace Wurk\Messaging\Otp;

use Wurk\Identity\Contracts\OtpChannel;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Support\ProviderSettings;

/** Sign-in codes to a mobile number: WhatsApp first (where configured), SMS as fallback. */
final readonly class PhoneOtpChannel implements OtpChannel
{
    public function __construct(private Messenger $messenger, private ProviderSettings $providers) {}

    public function name(): string
    {
        return 'phone';
    }

    public function send(string $destination, string $code, string $purpose): void
    {
        $whatsapp = $this->providers->active('whatsapp');
        $template = $whatsapp?->settings['otp_template'] ?? null;

        if ($whatsapp !== null && $template !== null) {
            $result = $this->messenger->whatsappTemplate($destination, $template, [$code], 'sign_in_code', $whatsapp->settings['otp_language'] ?? 'en');
            if ($result->ok) {
                return;
            }
        }

        $minutes = intdiv((int) config('wurk.identity.otp.ttl_seconds', 300), 60);
        $this->messenger->sms($destination, "Your WURK code is {$code}. It expires in {$minutes} minutes. Never share it with anyone.", 'sign_in_code');
    }
}
