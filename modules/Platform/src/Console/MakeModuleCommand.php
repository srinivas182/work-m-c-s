<?php

declare(strict_types=1);

namespace Wurk\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use JsonException;
use Wurk\Platform\Modules\ModuleRegistry;

final class MakeModuleCommand extends Command
{
    protected $signature = 'wurk:make-module
        {name : StudlyCase module name, e.g. Bookings}
        {--entitlement= : Package entitlement key (defaults to the module key)}
        {--core : Mark the module as core (always enabled, no entitlement)}';

    protected $description = 'Scaffold a new WURK module following the module contract';

    /** Directories every module starts with. */
    private const DIRECTORIES = [
        'src/Actions',
        'src/Contracts',
        'src/Events',
        'src/Http/Controllers',
        'src/Models',
        'src/Policies',
        'database/migrations',
        'tests/Unit',
    ];

    public function handle(Filesystem $files, ModuleRegistry $registry): int
    {
        $name = Str::studly((string) $this->argument('name'));
        $key = Str::kebab($name);
        $path = $registry->path().'/'.$name;

        if ($files->exists($path)) {
            $this->components->error("Module {$name} already exists.");

            return self::FAILURE;
        }

        if ($registry->has($key)) {
            $this->components->error("A module with key \"{$key}\" already exists.");

            return self::FAILURE;
        }

        $core = (bool) $this->option('core');
        $entitlement = $core ? null : ((string) ($this->option('entitlement') ?: $key));

        $replacements = [
            '{{ name }}' => $name,
            '{{ key }}' => $key,
            '{{ entitlement }}' => $entitlement === null ? 'null' : json_encode($entitlement, JSON_THROW_ON_ERROR),
            '{{ core }}' => $core ? 'true' : 'false',
        ];

        $stubs = $registry->path().'/Platform/stubs/module';

        foreach ($files->allFiles($stubs, true) as $stub) {
            $target = $path.'/'.str_replace(
                ['{{ name }}', '.stub'],
                [$name, ''],
                $stub->getRelativePathname(),
            );

            $files->ensureDirectoryExists(dirname($target));
            $files->put($target, strtr($stub->getContents(), $replacements));
        }

        foreach (self::DIRECTORIES as $directory) {
            $files->ensureDirectoryExists($path.'/'.$directory);
            $files->put($path.'/'.$directory.'/.gitkeep', '');
        }

        $this->registerAutoload($files, $name);

        $this->components->info("Module {$name} created at modules/{$name}.");
        $this->components->bulletList([
            'Run `composer dump-autoload` to load the new namespace.',
            "Visit /{$key} to see its starter page.",
        ]);

        return self::SUCCESS;
    }

    /**
     * Adds the module's PSR-4 namespaces to composer.json.
     */
    private function registerAutoload(Filesystem $files, string $name): void
    {
        $composerPath = base_path('composer.json');

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode($files->get($composerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->components->warn('Could not update composer.json: '.$e->getMessage());

            return;
        }

        /** @var array{autoload: array{psr-4: array<string, string>}, autoload-dev: array{psr-4: array<string, string>}} $composer */
        $composer['autoload']['psr-4']["Wurk\\{$name}\\"] = "modules/{$name}/src/";
        $composer['autoload-dev']['psr-4']["Wurk\\{$name}\\Tests\\"] = "modules/{$name}/tests/";

        $files->put(
            $composerPath,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
        );
    }
}
