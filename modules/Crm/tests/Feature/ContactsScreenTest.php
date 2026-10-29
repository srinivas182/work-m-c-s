<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Models\Contact;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->biz = $this->createTenant('glow-salon');
    $this->owner = $this->person();
    $this->manager = $this->person(['email' => 'musa@glow.co.za', 'phone' => '+27820000002']);
    foreach ([$this->owner, $this->manager] as $who) {
        $this->member($who, $this->biz);
    }
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->biz, $fn);
    [$this->sipho, $this->work, $this->lerato] = ($this->in)(function () {
        $main = app(BranchDirectory::class)->main();
        $sandton = app(BranchDirectory::class)->add('Sandton', null, null);
        app(Team::class)->add($this->owner->id, 'Thandi', 'owner');
        app(Team::class)->setBranches(app(Team::class)->add($this->manager->id, 'Musa', 'branch-manager'), [$sandton]);
        $book = app(ContactBook::class);
        $sipho = $book->remember('Sipho', '082 555 0101', null, 'booking', 'b1', $main, 'Braids');
        $work = $book->remember('Sipho (work)', null, 'sipho@work.co.za', 'order', 'o1', $main, 'Order #1001');
        $lerato = $book->remember('Lerato', '083 000 0000', null, 'booking', 'b2', $sandton, 'Nails');
        Contact::query()->whereKey($lerato)->update(['tags' => ['vip']]);

        return [$sipho, $work, $lerato];
    });
    $this->base = $this->app('/glow-salon/contacts');
});

it('finds contacts by number however it’s written, and by tag', function () {
    $this->actingAs($this->owner)->get("{$this->base}?q=".urlencode('+27 82 555 0101'))->assertInertia(fn (Assert $p) => $p->component('Crm::Contacts')->has('contacts', 1)->where('contacts.0.name', 'Sipho'));
    $this->actingAs($this->owner)->get("{$this->base}?tag=vip")->assertInertia(fn (Assert $p) => $p->has('contacts', 1)->where('contacts.0.name', 'Lerato'));
});

it('merges two contacts into one history, and splits them back exactly', function () {
    $this->actingAs($this->owner)->post("{$this->base}/{$this->sipho}/merge", ['other' => $this->work])->assertSessionHas('status', 'Sipho (work) merged into Sipho. You can split them again from this page.');
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->has('contacts', 2)); // the merged one is hidden
    $this->actingAs($this->owner)->get("{$this->base}/{$this->sipho}")->assertInertia(fn (Assert $p) => $p->component('Crm::Contact')
        ->has('history', 2)->where('contact.email', 'sipho@work.co.za')->where('merged.0.name', 'Sipho (work)'));

    $this->actingAs($this->owner)->post("{$this->base}/{$this->sipho}/split/{$this->work}")->assertSessionHas('status', 'Sipho (work) is a separate contact again.');
    $this->actingAs($this->owner)->get("{$this->base}/{$this->work}")->assertInertia(fn (Assert $p) => $p->has('history', 1)->where('history.0.summary', 'Order #1001'));
    $this->actingAs($this->owner)->get("{$this->base}/{$this->sipho}")->assertInertia(fn (Assert $p) => $p->has('history', 1)->where('history.0.summary', 'Braids'));
});

it('exports for owners, and shows a branch manager only their branch’s contacts (no export)', function () {
    $csv = $this->actingAs($this->owner)->get("{$this->base}/export")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
    expect($csv)->toStartWith('Name,Phone,Email,Tags,"Marketing consent","Last seen"')->toContain('Lerato,+27830000000,,vip');

    $this->actingAs($this->manager)->get($this->base)->assertInertia(fn (Assert $p) => $p->has('contacts', 1)->where('contacts.0.name', 'Lerato')->where('canManage', false));
    $this->actingAs($this->manager)->get("{$this->base}/{$this->sipho}")->assertNotFound();
    $this->actingAs($this->manager)->get("{$this->base}/export")->assertForbidden();
});
