<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

/** Which version of WURK is running (Sprint 18.0) — set at build time (WURK_RELEASE / WURK_COMMIT), else the VERSION file. */
final class Release
{
    public static function current(): string
    {
        return mb_substr((string) config('wurk.release', 'dev'), 0, 40) ?: 'dev';
    }

    public static function commit(): ?string
    {
        $c = config('wurk.commit');

        return is_string($c) && $c !== '' ? substr($c, 0, 12) : null;
    }
}
