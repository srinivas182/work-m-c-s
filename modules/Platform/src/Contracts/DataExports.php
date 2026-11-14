<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * "Your data" (Sprint 16.6): every module offers what it keeps for a business — tables (one CSV each) and files — for
 * the one-click full export. Everything runs inside the business's context; a permission limits who gets that part.
 */
interface DataExports
{
    /** @param \Closure(): iterable<array<string, mixed>> $rows each row keyed by column name */
    public function table(string $key, string $label, ?string $permission, \Closure $rows): void;

    /** @param \Closure(): iterable<array{path: string, contents: \Closure(): string}> $files paths inside the export's folder for this set */
    public function files(string $key, string $label, ?string $permission, \Closure $files): void;

    /** @return array<string, array{label: string, permission: string|null, rows: \Closure}> */
    public function tables(): array;

    /** @return array<string, array{label: string, permission: string|null, files: \Closure}> */
    public function fileSets(): array;
}
