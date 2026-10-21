<?php

declare(strict_types=1);

use Wurk\Access\Permissions\Permission;
use Wurk\Access\Permissions\PermissionRegistry;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Platform\Modules\ModuleServiceProvider;

it('collects permissions declared by modules with their scope', function () {
    $registry = app(PermissionRegistry::class);

    expect($registry->get('tenants.manage')?->scope)->toBe(Permission::PLATFORM)
        ->and($registry->get('team.manage')?->scope)->toBe(Permission::BUSINESS)
        ->and($registry->get('team.manage')?->module)->toBe('IdentityServiceProvider')
        ->and($registry->keys(Permission::PLATFORM))->not->toContain('team.manage');
});

it('only references declared permissions in system roles', function (string $role) {
    $listed = SystemRoles::DEFINITIONS[$role]['permissions'];
    $declared = app(PermissionRegistry::class)->keys(SystemRoles::DEFINITIONS[$role]['scope']);

    expect(array_diff($listed, ['*', ...$declared]))->toBe([]);
})->with(array_keys(SystemRoles::DEFINITIONS));

it('rejects malformed or duplicate declarations', function (array $declarations, string $message) {
    app()->register(new class(app(), $declarations) extends ModuleServiceProvider
    {
        public function __construct($app, private array $declarations)
        {
            parent::__construct($app);
        }

        public function permissions(): array
        {
            return $this->declarations;
        }
    });

    app(PermissionRegistry::class)->flush();
    app(PermissionRegistry::class)->all();
})->with([
    [['Not Valid'], 'Invalid permission key'],
    [[['key' => 'thing.do', 'scope' => 'galaxy']], 'invalid scope'],
    [['team.view'], 'declared twice'],
])->throws(InvalidArgumentException::class);
