<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Wurk\Tenancy\Actions\CreateTenant;

final class CreateTenantCommand extends Command
{
    protected $signature = 'tenants:create
        {name : Business name}
        {--handle= : URL handle (defaults to a slug of the name)}
        {--country=ZA}
        {--timezone=Africa/Johannesburg}';

    protected $description = 'Provision a business on the least-loaded shard';

    public function handle(CreateTenant $create): int
    {
        $name = (string) $this->argument('name');
        $handle = (string) ($this->option('handle') ?: str($name)->slug());

        try {
            $tenant = $create($name, $handle, (string) $this->option('country'), (string) $this->option('timezone'));
        } catch (ValidationException $e) {
            $this->components->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->components->info("Created {$tenant->name} ({$tenant->handle}) on shard {$tenant->shard?->name}.");

        return self::SUCCESS;
    }
}
