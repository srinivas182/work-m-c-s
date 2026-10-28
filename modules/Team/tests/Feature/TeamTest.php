<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->biz = $this->createTenant('glow-salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->biz);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->biz, $fn);
    [$this->me, $this->sipho] = ($this->in)(fn () => [
        app(Team::class)->add($this->owner->id, 'Thandi', 'owner')->id,
        app(Team::class)->add((string) Str::ulid(), 'Sipho', 'staff')->id,
    ]);
    $this->base = $this->app('/glow-salon/settings/team');
    $this->roster = fn () => ($this->in)(fn () => collect(app(TeamDirectory::class)->roster())->keyBy('id'));
});

it('invites people, and cancels an invitation', function () {
    $this->actingAs($this->owner)->post("{$this->base}/invitations", ['email' => 'Lerato@Example.com', 'role' => 'staff'])->assertSessionHas('status', 'Invitation sent to Lerato@Example.com.');
    $page = $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Team::Team')->has('members', 2)->where('pending.0.email', 'lerato@example.com')->where('pending.0.role', 'staff'));
    $id = $page->viewData('page')['props']['pending'][0]['id'];
    $this->actingAs($this->owner)->delete("{$this->base}/invitations/{$id}")->assertSessionHas('status', 'Invitation cancelled.');
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->has('pending', 0));
});

it('makes a branch manager — limited to branches, never “all” — and keeps owners safe', function () {
    $this->actingAs($this->owner)->put("{$this->base}/{$this->sipho}/role", ['role' => 'branch-manager'])->assertSessionHas('status', 'Role changed.');
    $main = ($this->in)(fn () => app(BranchDirectory::class)->main());
    expect(($this->roster)()[$this->sipho])->toMatchArray(['role' => 'branch-manager', 'all_branches' => false, 'branches' => [$main]]);
    $this->actingAs($this->owner)->put("{$this->base}/{$this->sipho}/branches", ['all' => true])
        ->assertSessionHasErrors(['branches' => 'A branch manager looks after particular branches — choose them.']);

    $this->actingAs($this->owner)->put("{$this->base}/{$this->me}/role", ['role' => 'staff'])
        ->assertSessionHasErrors(['member' => 'You can’t change your own role or remove yourself — ask another owner.']);
    ($this->in)(fn () => expect(fn () => app(TeamDirectory::class)->setRole($this->me, 'staff'))->toThrow(DomainException::class, 'A business must always have at least one owner.'));

    $this->actingAs($this->owner)->delete("{$this->base}/{$this->sipho}")->assertSessionHas('status', 'Removed from the team. Their past work stays on record.');
    expect(($this->roster)()[$this->sipho]['active'])->toBeFalse();
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->has('members', 1));
});
