<?php

declare(strict_types=1);

use Wurk\Platform\Modules\Exceptions\InvalidModuleManifest;
use Wurk\Platform\Modules\Exceptions\ModuleDependencyException;
use Wurk\Platform\Modules\ModuleRegistry;

/**
 * @param  array<string, array<string, mixed>>  $modules  directory name => manifest
 */
function moduleFixture(array $modules): string
{
    $root = sys_get_temp_dir().'/wurk-modules-'.bin2hex(random_bytes(6));

    foreach ($modules as $dir => $manifest) {
        mkdir("{$root}/{$dir}", 0777, true);
        file_put_contents("{$root}/{$dir}/module.json", json_encode($manifest));
    }

    return $root;
}

function manifest(string $name, string $key, array $dependsOn = []): array
{
    return ['name' => $name, 'key' => $key, 'version' => '1.0.0', 'depends_on' => $dependsOn];
}

it('orders modules so dependencies boot first', function () {
    $registry = new ModuleRegistry(moduleFixture([
        'Bookings' => manifest('Bookings', 'bookings', ['platform', 'crm']),
        'Crm' => manifest('Crm', 'crm', ['platform']),
        'Platform' => manifest('Platform', 'platform'),
    ]));

    expect(array_keys($registry->all()))->toBe(['platform', 'crm', 'bookings']);
});

it('rejects a missing dependency', function () {
    (new ModuleRegistry(moduleFixture([
        'Bookings' => manifest('Bookings', 'bookings', ['crm']),
    ])))->all();
})->throws(ModuleDependencyException::class, 'depends on "crm"');

it('rejects circular dependencies', function () {
    (new ModuleRegistry(moduleFixture([
        'Alpha' => manifest('Alpha', 'alpha', ['beta']),
        'Beta' => manifest('Beta', 'beta', ['alpha']),
    ])))->all();
})->throws(ModuleDependencyException::class, 'Circular');

it('rejects duplicate keys', function () {
    (new ModuleRegistry(moduleFixture([
        'Alpha' => manifest('Alpha', 'same'),
        'Beta' => manifest('Beta', 'same'),
    ])))->all();
})->throws(ModuleDependencyException::class, 'same key');

it('rejects a manifest whose name does not match its directory', function () {
    (new ModuleRegistry(moduleFixture([
        'Alpha' => manifest('Beta', 'beta'),
    ])))->all();
})->throws(InvalidModuleManifest::class, 'must match its directory');

it('rejects an invalid key', function () {
    (new ModuleRegistry(moduleFixture([
        'Alpha' => manifest('Alpha', 'Not_Valid'),
    ])))->all();
})->throws(InvalidModuleManifest::class, 'kebab-case');

it('rejects malformed json', function () {
    $root = sys_get_temp_dir().'/wurk-modules-'.bin2hex(random_bytes(6));
    mkdir("{$root}/Alpha", 0777, true);
    file_put_contents("{$root}/Alpha/module.json", '{not json');

    (new ModuleRegistry($root))->all();
})->throws(InvalidModuleManifest::class, 'not valid JSON');

it('discovers the real installed modules', function () {
    $registry = new ModuleRegistry(dirname(__DIR__, 3));

    expect($registry->has('platform'))->toBeTrue()
        ->and($registry->has('health'))->toBeTrue()
        ->and(array_key_first($registry->all()))->toBe('platform');
});
