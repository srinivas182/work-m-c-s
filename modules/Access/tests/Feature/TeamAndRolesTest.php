<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\AccessManager;
use Wurk\Access\Actions\CustomRoles;
use Wurk\Access\Actions\PlatformAccess;
use Wurk\Access\Actions\Team;
use Wurk\Access\Contracts\BelongsToBranch;
use Wurk\Access\Exceptions\AccessRuleViolation;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Models\Role;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Identity\Models\Identity;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Navigation\NavigationBuilder;
use Wurk\Tenancy\Contracts\BelongsToTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Branch;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

final class BranchProbe extends Model
{
    use BelongsToBranch;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'branch_probes';

    protected $fillable = ['branch_id', 'label'];
}

beforeEach(function () {
    $this->createShards(1);
    $this->tenant = $this->createTenant('glow-salon');
    $this->context = app(TenantContext::class);
    $this->team = app(Team::class);
});

it('always keeps at least one owner', function () {
    $owner = $this->person();
    $member = $this->context->run($this->tenant, fn () => $this->team->add($owner->id, 'Owner', SystemRoles::OWNER));

    expect(fn () => $this->context->run($this->tenant, fn () => $this->team->revoke($member, SystemRoles::OWNER)))
        ->toThrow(AccessRuleViolation::class, 'at least one owner');

    $second = $this->person(['email' => 'two@glow.co.za', 'phone' => null]);
    $this->context->run($this->tenant, fn () => $this->team->add($second->id, 'Second', SystemRoles::OWNER));
    $this->context->run($this->tenant, fn () => $this->team->revoke($member, SystemRoles::OWNER));

    expect($this->context->run($this->tenant, fn () => Gate::forUser($owner)->allows('team.manage')))->toBeFalse();
});

it('limits branch managers to their branches and lets owners see all', function () {
    $this->context->run($this->tenant, function () {
        Schema::connection($this->context->connectionName())->create('branch_probes', function ($t) {
            $t->ulid('id')->primary();
            $t->ulid('tenant_id');
            $t->ulid('branch_id');
            $t->string('label');
            $t->timestamps();
        });
        $this->main = Branch::query()->where('code', 'main')->firstOrFail();
        $this->sandton = Branch::query()->create(['name' => 'Sandton', 'code' => 'sandton', 'timezone' => 'Africa/Johannesburg']);
        BranchProbe::query()->create(['branch_id' => $this->main->id, 'label' => 'main booking']);
        BranchProbe::query()->create(['branch_id' => $this->sandton->id, 'label' => 'sandton booking']);
    });

    $owner = $this->person();
    $manager = $this->person(['email' => 'manager@glow.co.za', 'phone' => null]);
    $this->context->run($this->tenant, function () use ($owner, $manager) {
        $this->team->add($owner->id, 'Owner', SystemRoles::OWNER);
        $member = $this->team->add($manager->id, 'Manager', SystemRoles::BRANCH_MANAGER);
        $this->team->setBranches($member, [$this->sandton->id]);
    });

    $this->actingAs($manager);
    expect($this->context->run($this->tenant, fn () => BranchProbe::query()->pluck('label')->all()))->toBe(['sandton booking']);

    $this->actingAs($owner);
    app(AccessManager::class)->flush();
    expect($this->context->run($this->tenant, fn () => BranchProbe::query()->count()))->toBe(2);
});

it('builds custom business roles only from business permissions', function () {
    $roles = app(CustomRoles::class);
    $person = $this->person();

    $role = $this->context->run($this->tenant, fn () => $roles->createBusinessRole('Receptionist', ['team.view', 'branches.view']));
    $this->context->run($this->tenant, fn () => $this->team->add($person->id, 'Reception', $role->key()));

    expect($this->context->run($this->tenant, fn () => Gate::forUser($person)->allows('team.view')))->toBeTrue()
        ->and($this->context->run($this->tenant, fn () => Gate::forUser($person)->allows('team.manage')))->toBeFalse()
        ->and(fn () => $this->context->run($this->tenant, fn () => $roles->createBusinessRole('Sneaky', ['tenants.manage'])))->toThrow(ValidationException::class)
        ->and(fn () => $this->context->run($this->tenant, fn () => $roles->deleteBusinessRole($role)))->toThrow(AccessRuleViolation::class);
});

it('keeps at least one super admin and rejects business roles for staff', function () {
    $staff = $this->staff();
    $access = app(PlatformAccess::class);
    $access->grant($staff->id, SystemRoles::SUPER_ADMIN);

    expect(fn () => $access->revoke($staff->id, SystemRoles::SUPER_ADMIN))->toThrow(AccessRuleViolation::class)
        ->and(fn () => $access->grant($staff->id, SystemRoles::OWNER))->toThrow(AccessRuleViolation::class);
});

it('wires people up from the command line with the right roles', function () {
    $this->artisan('identities:create', ['email' => 'ops@wurk.co.za', '--staff' => true])->assertSuccessful();
    $this->artisan('identities:create', ['email' => 'owner@glow.co.za', '--tenant' => ['glow-salon']])->assertSuccessful();
    $this->artisan('identities:create', ['email' => 'desk@glow.co.za', '--tenant' => ['glow-salon'], '--role' => 'staff'])->assertSuccessful();

    $ops = Identity::query()->where('email', 'ops@wurk.co.za')->firstOrFail();
    $owner = Identity::query()->where('email', 'owner@glow.co.za')->firstOrFail();
    $desk = Identity::query()->where('email', 'desk@glow.co.za')->firstOrFail();

    expect(Gate::forUser($ops)->allows('tenants.manage'))->toBeTrue()
        ->and($this->context->run($this->tenant, fn () => Gate::forUser($owner)->allows('team.manage')))->toBeTrue()
        ->and($this->context->run($this->tenant, fn () => Gate::forUser($desk)->allows('team.manage')))->toBeFalse()
        ->and($this->context->run($this->tenant, fn () => Gate::forUser($desk)->allows('branches.view')))->toBeTrue();
});

it('lets policies answer non-permission abilities using permissions', function () {
    $staff = $this->staff();
    app(PlatformAccess::class)->grant($staff->id, 'support');

    expect(Gate::forUser($staff)->allows('view', $this->tenant))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('update', $this->tenant))->toBeFalse();

    $owner = $this->person();
    $this->context->run($this->tenant, fn () => $this->team->add($owner->id, 'Owner', SystemRoles::OWNER));
    $this->context->run($this->tenant, function () use ($owner) {
        $main = Branch::query()->where('code', 'main')->firstOrFail();
        expect(Gate::forUser($owner)->allows('update', $main))->toBeTrue()
            ->and(Gate::forUser($owner)->allows('delete', $main))->toBeFalse(); // the default branch stays
    });
});

it('prunes permissions that modules no longer declare from custom roles', function () {
    $platformRole = PlatformRole::query()->create(['name' => 'Legacy', 'permissions' => ['tenants.view', 'retired.permission']]);
    $this->context->run($this->tenant, fn () => Role::query()->create(['name' => 'Old', 'permissions' => ['team.view', 'gone.away']]));

    $this->artisan('permissions:sync')->expectsOutputToContain('2 custom role(s) updated')->assertSuccessful();

    expect($platformRole->fresh()->permissions)->toBe(['tenants.view'])
        ->and($this->context->run($this->tenant, fn () => Role::query()->value('permissions')))->toBe(['team.view']);
});

it('hides navigation items people are not allowed to use', function () {
    $provider = new class(app()) extends ModuleServiceProvider
    {
        public function menu(): array
        {
            return [['label' => 'Businesses', 'route' => 'home', 'permission' => 'tenants.view', 'section' => 'Test']];
        }
    };
    app()->register($provider);

    $staff = $this->staff();
    $builder = app(NavigationBuilder::class);
    $request = request()->setUserResolver(fn () => $staff);

    expect(collect($builder->build($request))->pluck('label'))->not->toContain('Businesses');

    app(PlatformAccess::class)->grant($staff->id, 'support');
    expect(collect($builder->build($request))->pluck('label'))->toContain('Businesses');
});
