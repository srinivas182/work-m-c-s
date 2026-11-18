<?php

declare(strict_types=1);

namespace Wurk\Payments\Gateways\MobileMoney;

use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;

/**
 * A mobile money provider (Sprint 22.2): a prompt is pushed to the customer's phone, they approve it with their PIN,
 * and the money goes to the business's own till or wallet. Callbacks aren't trusted on their own: the result is always
 * read back from the provider. Where each works: PlatformMethods::COUNTRIES.
 */
interface MobileMoneyGateway
{
    /**
     * Push the prompt to the customer's phone (international digits, e.g. 254712345678). Returns the provider's
     * request id, or a failure reason when the provider refused it straight away.
     *
     * @return array{0: string|null, 1: string|null} [request id, failure reason]
     */
    public function request(PaymentAccount $account, PaymentAttempt $attempt, string $msisdn, string $country, string $callbackUrl): array;

    /** Ask the provider how the prompt stands. */
    public function status(PaymentAccount $account, PaymentAttempt $attempt, string $country): MobileMoneyResult;

    /**
     * The provider's request id in a callback (unsigned, so only a hint to check the status), or null.
     *
     * @param  array<string, mixed>  $payload
     */
    public function callbackId(array $payload): ?string;

    /** Whether the keys work (a token can be had). */
    public function check(PaymentAccount $account): bool;
}
