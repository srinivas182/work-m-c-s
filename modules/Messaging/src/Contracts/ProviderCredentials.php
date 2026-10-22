<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/** Read access to a switched-on provider's settings for other modules (e.g. payment gateways). */
interface ProviderCredentials
{
    /** @return array{driver: string, credentials: array<string, string>, settings: array<string, string>}|null */
    public function active(string $channel): ?array;
}
