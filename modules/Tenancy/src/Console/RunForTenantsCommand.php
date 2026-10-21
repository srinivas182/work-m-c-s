<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Tenant;

final class RunForTenantsCommand extends Command
{
    protected $signature = 'tenants:run
        {artisan : The artisan command to run, e.g. "cache:clear"}
        {--tenant=* : Only these handles (default: all active tenants)}';

    protected $description = 'Run an artisan command inside each tenant context';

    public function handle(TenantContext $context, Kernel $artisan): int
    {
        $query = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->orderBy('handle');
        $only = array_filter((array) $this->option('tenant'));
        if ($only !== []) {
            $query->whereIn('handle', $only);
        }

        $failed = 0;
        foreach ($query->cursor() as $tenant) {
            $exit = $context->run($tenant, fn () => $artisan->call((string) $this->argument('artisan')));
            $this->components->twoColumnDetail($tenant->handle, $exit === 0 ? '<fg=green>done</>' : '<fg=red>failed</>');
            $failed += $exit === 0 ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
