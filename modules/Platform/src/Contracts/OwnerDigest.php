<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/** Modules register their part of the owner's evening WhatsApp summary (21.6). */
interface OwnerDigest
{
    /** @param  class-string<OwnerDigestSource>  $source */
    public function register(string $source): void;

    /** @return list<OwnerDigestSource> */
    public function sources(): array;
}
