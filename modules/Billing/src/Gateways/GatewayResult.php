<?php

declare(strict_types=1);

namespace Wurk\Billing\Gateways;

/** What the payment provider says happened to one charge. */
final readonly class GatewayResult
{
    /**
     * @param  'succeeded'|'failed'|'pending'  $status
     * @param  array{code: string, reusable: bool, signature: string, brand: string|null, last4: string|null, expires: string|null}|null  $card
     */
    public function __construct(
        public string $status,
        public ?int $amountCents = null,
        public ?string $currency = null,
        public ?string $gatewayId = null,
        public ?array $card = null,
        public ?string $message = null,
    ) {}

    public static function failed(string $message): self
    {
        return new self('failed', message: mb_substr($message, 0, 255));
    }
}
