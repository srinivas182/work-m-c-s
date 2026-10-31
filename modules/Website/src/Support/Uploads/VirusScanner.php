<?php

declare(strict_types=1);

namespace Wurk\Website\Support\Uploads;

/** Scans a file's contents for malware. */
interface VirusScanner
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    /** CLEAN, INFECTED, or null when the scanner can't be reached (the file is held, never released). */
    public function scan(string $contents): ?string;
}
