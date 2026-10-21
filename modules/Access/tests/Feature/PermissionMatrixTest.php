<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\AccessManager;
use Wurk\Access\Actions\PlatformAccess;
use Wurk\Access\Actions\Team;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Permissions\PermissionRegistry;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

/*
| One case per (system role × permission × place). For each, the expected answer
| comes straight from the role definition, so the matrix stays exhaustive as
| modules declare new permissions.
*/

function expectedFor(string $role, string $permission): bool
{
    $granted = SystemRoles::DEFINITIONS[$role]['permissions'];
    $scope = app(PermissionRegistry::class)->get($permission)?->scope;

    return $scope === SystemRoles::scope($role) && ($granted === ['*'] || in_array($permission, $granted, true));
}

beforeEach(function () {
    [$one, $two] = $this->createShards(2);
    $this->a = $this->createTenant('tenant-a', $one);
    $this->b = $this->createTenant('tenant-b', $two);
    $this->context = app(TenantContext::class);
});

it('grants business roles exactly their permissions — in their business only', function (string $role) {
    $person = $this->person();
    $this->context->run($this->a, fn () => app(Team::class)->add($person->id, 'Thandi', $role));
    $registry = app(PermissionRegistry::class);

    foreach (array_keys($registry->all()) as $permission) {
        $inA = $this->context->run($this->a, fn () => Gate::forUser($person)->allows($permission));
        $inB = $this->context->run($this->b, fn () => Gate::forUser($person)->allows($permission));
        $nowhere = Gate::forUser($person)->allows($permission);

        expect($inA)->toBe(expectedFor($role, $permission), "{$role} / {$permission} in own business")
            ->and($inB)->toBeFalse("{$role} / {$permission} in another business")
            ->and($nowhere)->toBeFalse("{$role} / {$permission} with no business");
    }
})->with(SystemRoles::keys(Permission::BUSINESS));

it('grants platform roles exactly their permissions — and nothing inside businesses', function (string $role) {
    $staff = $this->staff();
    app(PlatformAccess::class)->grant($staff->id, $role);

    foreach (array_keys(app(PermissionRegistry::class)->all()) as $permission) {
        expect(Gate::forUser($staff)->allows($permission))->toBe(expectedFor($role, $permission), "{$role} / {$permission}")
            ->and($this->context->run($this->a, fn () => Gate::forUser($staff)->allows($permission)))
            ->toBe(expectedFor($role, $permission), "{$role} / {$permission} while a business is active");
    }
})->with(SystemRoles::keys(Permission::PLATFORM));

it('denies everything to someone with no roles', function () {
    $nobody = $this->person();
    $this->context->run($this->a, fn () => app(Team::class)->add($nobody->id, 'N', SystemRoles::STAFF));
    $this->context->run($this->a, fn () => app(Team::class)->revoke(app(AccessManager::class)->member($nobody->id), SystemRoles::STAFF));

    foreach (array_keys(app(PermissionRegistry::class)->all()) as $permission) {
        expect($this->context->run($this->a, fn () => Gate::forUser($nobody)->allows($permission)))->toBeFalse();
    }
});

it('denies undeclared permissions instead of guessing', function () {
    $staff = $this->staff();
    app(PlatformAccess::class)->grant($staff->id, SystemRoles::SUPER_ADMIN);

    expect(app(AccessManager::class)->allows($staff->id, 'made.up'))->toBeFalse();
});
