<?php

declare(strict_types=1);

namespace Wurk\Identity\Onboarding;

use Illuminate\Validation\ValidationException;
use Wurk\Identity\Contracts\Verification;
use Wurk\Identity\Exceptions\OtpThrottled;
use Wurk\Identity\Otp\OtpManager;
use Wurk\Identity\Support\Destination;

final readonly class VerificationService implements Verification
{
    public function __construct(private OtpManager $otp) {}

    public function send(string $contact, string $purpose, ?string $ip, string $field = 'contact'): array
    {
        $destination = Destination::parse($contact, $field);

        try {
            $this->otp->request($destination, $purpose, $ip);
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages([$field => "Please wait {$e->retryAfterSeconds} seconds before asking for another code."]);
        }

        return ['type' => $destination->type, 'value' => $destination->value, 'masked' => $destination->masked()];
    }

    public function confirm(string $contact, string $code, string $purpose): bool
    {
        return $this->otp->confirm(Destination::parse($contact), $code, $purpose);
    }

    public function fingerprint(string $contact): string
    {
        return Destination::parse($contact)->hash();
    }
}
