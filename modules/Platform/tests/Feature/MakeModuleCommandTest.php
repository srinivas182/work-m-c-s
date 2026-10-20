<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Wurk\Platform\Modules\ModuleManifest;
use Wurk\Platform\Modules\ModuleRegistry;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->root = sys_get_temp_dir().'/wurk-make-'.bin2hex(random_bytes(6));
    $this->files->copyDirectory(base_path('modules/Platform'), $this->root.'/Platform');

    $this->composer = base_path('composer.json');
    $this->composerBackup = $this->files->get($this->composer);

    app()->instance(ModuleRegistry::class, new ModuleRegistry($this->root));
});

afterEach(function () {
    $this->files->put($this->composer, $this->composerBackup);
    $this->files->deleteDirectory($this->root);
});

it('scaffolds a module that satisfies the module contract', function () {
    $this->artisan('wurk:make-module', ['name' => 'loyalty-points'])->assertSuccessful();

    $path = $this->root.'/LoyaltyPoints';

    $manifest = ModuleManifest::fromFile($path.'/module.json');
    expect($manifest->key)->toBe('loyalty-points')
        ->and($manifest->entitlement)->toBe('loyalty-points')
        ->and($manifest->dependsOn)->toBe(['platform'])
        ->and($path.'/src/LoyaltyPointsServiceProvider.php')->toBeFile()
        ->and($path.'/routes/web.php')->toBeFile()
        ->and($path.'/resources/js/pages/Index.tsx')->toBeFile()
        ->and($path.'/tests/Feature/LoyaltyPointsModuleTest.php')->toBeFile()
        ->and($path.'/src/Contracts')->toBeDirectory();

    expect(file_get_contents($path.'/src/LoyaltyPointsServiceProvider.php'))
        ->toContain('namespace Wurk\LoyaltyPoints;')
        ->not->toContain('{{');

    $composer = json_decode($this->files->get($this->composer), true);
    expect($composer['autoload']['psr-4'])->toHaveKey('Wurk\\LoyaltyPoints\\');
});

it('creates core modules without an entitlement', function () {
    $this->artisan('wurk:make-module', ['name' => 'Audit', '--core' => true])->assertSuccessful();

    $manifest = ModuleManifest::fromFile($this->root.'/Audit/module.json');
    expect($manifest->core)->toBeTrue()->and($manifest->entitlement)->toBeNull();
});

it('refuses to overwrite an existing module', function () {
    $this->artisan('wurk:make-module', ['name' => 'Platform'])->assertFailed();
});
