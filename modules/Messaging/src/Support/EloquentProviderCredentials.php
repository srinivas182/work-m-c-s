<?php

declare(strict_types=1);

namespace Wurk\Messaging\Support;

use Wurk\Messaging\Contracts\ProviderCredentials;

final readonly class EloquentProviderCredentials implements ProviderCredentials
{
    public function __construct(private ProviderSettings $settings) {}

    public function active(string $channel): ?array
    {
        $s = $this->settings->active($channel);

        return $s === null ? null : ['driver' => $s->driver, 'credentials' => $s->credentials, 'settings' => $s->settings ?? []];
    }
}
