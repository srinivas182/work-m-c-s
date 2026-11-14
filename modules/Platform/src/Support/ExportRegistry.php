<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\DataExports;

final class ExportRegistry implements DataExports
{
    /** @var array<string, array{label: string, permission: string|null, rows: \Closure}> */
    private array $tables = [];

    /** @var array<string, array{label: string, permission: string|null, files: \Closure}> */
    private array $files = [];

    public function table(string $key, string $label, ?string $permission, \Closure $rows): void
    {
        $this->tables[$key] = ['label' => $label, 'permission' => $permission, 'rows' => $rows];
    }

    public function files(string $key, string $label, ?string $permission, \Closure $files): void
    {
        $this->files[$key] = ['label' => $label, 'permission' => $permission, 'files' => $files];
    }

    public function tables(): array
    {
        ksort($this->tables);

        return $this->tables;
    }

    public function fileSets(): array
    {
        ksort($this->files);

        return $this->files;
    }
}
