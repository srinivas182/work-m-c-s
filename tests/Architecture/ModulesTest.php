<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Module boundary rules (ADR-0001)
|--------------------------------------------------------------------------
| A module may depend on another module only through that module's
| Contracts\ and Events\ namespaces. Platform is the shared kernel and may
| be used by everyone.
*/

$modulesRoot = dirname(__DIR__, 2).'/modules';
$modules = array_map('basename', glob($modulesRoot.'/*', GLOB_ONLYDIR) ?: []);

foreach ($modules as $module) {
    foreach ($modules as $other) {
        if ($other === $module || $other === 'Platform') {
            continue;
        }

        $internal = array_values(array_filter(
            array_map('basename', glob("{$modulesRoot}/{$other}/src/*", GLOB_ONLYDIR) ?: []),
            fn (string $dir) => ! in_array($dir, ['Contracts', 'Events'], true),
        ));

        if ($internal === []) {
            continue;
        }

        arch("{$module} uses only {$other}'s contracts and events")
            ->expect("Wurk\\{$module}")
            ->not->toUse(array_map(fn (string $dir) => "Wurk\\{$other}\\{$dir}", $internal));
    }
}

arch('platform does not depend on feature modules')
    ->expect('Wurk\Platform')
    ->not->toUse(array_map(
        fn (string $m) => "Wurk\\{$m}",
        array_values(array_diff($modules, ['Platform'])),
    ));

arch('modules declare strict types')
    ->expect(['Wurk', 'App'])
    ->toUseStrictTypes();

arch('no debugging helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('env() is only read inside config files')
    ->expect('env')
    ->not->toBeUsedIn(['Wurk', 'App']);

foreach ($modules as $module) {
    arch("{$module} service provider extends the module contract")
        ->expect("Wurk\\{$module}\\{$module}ServiceProvider")
        ->toExtend('Wurk\Platform\Modules\ModuleServiceProvider');
}

arch('controllers are final and suffixed')
    ->expect(array_map(fn (string $m) => "Wurk\\{$m}\\Http\\Controllers", $modules))
    ->toBeFinal()
    ->toHaveSuffix('Controller');
