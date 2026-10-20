<?php

declare(strict_types=1);

namespace Wurk\Platform\Console;

use Illuminate\Console\Command;
use Wurk\Platform\Modules\ModuleRegistry;

final class ListModulesCommand extends Command
{
    protected $signature = 'wurk:modules';

    protected $description = 'List installed WURK modules in boot order';

    public function handle(ModuleRegistry $registry): int
    {
        $rows = [];

        foreach ($registry->all() as $manifest) {
            $rows[] = [
                $manifest->name,
                $manifest->key,
                $manifest->version,
                $manifest->core ? 'yes' : 'no',
                $manifest->entitlement ?? '—',
                $manifest->dependsOn === [] ? '—' : implode(', ', $manifest->dependsOn),
            ];
        }

        $this->table(['Module', 'Key', 'Version', 'Core', 'Entitlement', 'Depends on'], $rows);

        return self::SUCCESS;
    }
}
