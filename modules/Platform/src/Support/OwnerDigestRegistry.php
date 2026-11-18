<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\OwnerDigest;
use Wurk\Platform\Contracts\OwnerDigestSource;

final class OwnerDigestRegistry implements OwnerDigest
{
    /** @var list<class-string<OwnerDigestSource>> */
    private array $sources = [];

    public function register(string $source): void
    {
        if (! in_array($source, $this->sources, true)) {
            $this->sources[] = $source;
        }
    }

    public function sources(): array
    {
        return array_map(fn (string $class) => app($class), $this->sources);
    }
}
