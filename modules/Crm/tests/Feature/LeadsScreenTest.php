<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\LeadBook;
use Wurk\Crm\Models\Contact;
use Wurk\Crm\Models\Lead;
use Wurk\Crm\Models\Stage;
use Wurk\Crm\Support\Leads;
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
    [$this->wedding, $this->sandtonLead, $this->zanele] = ($this->in)(function () {
        $sandton = app(BranchDirectory::class)->add('Sandton', null, null);
        app(Team::class)->add($this->owner->id, 'Thandi', 'owner');
        app(Team::class)->setBranches(app(Team::class)->add($this->manager->id, 'Musa', 'branch-manager'), [$sandton]);
        $zanele = app(Team::class)->add((string) Str::ulid(), 'Zanele', 'staff')->id;
        $sipho = app(ContactBook::class)->remember('Sipho', '082 555 0101', null, 'form', 'f1', null, 'Quote');
        $lerato = app(ContactBook::class)->remember('Lerato', '083 000 0000', null, 'form', 'f2', $sandton, 'Quote');

        return [app(LeadBook::class)->open($sipho, 'Wedding hair for 6', 'quote', 450000), app(LeadBook::class)->open($lerato, 'Nails for a party', 'form', null, $sandton), $zanele];
    });
    $this->base = $this->app('/glow-salon/leads');
});

it('gives the board and the lead page what they show, and records every change', function () {
    $this->actingAs($this->owner)->get("{$this->base}?view=list")->assertInertia(fn (Assert $p) => $p->component('Crm::Leads')->where('view', 'list')->has('stages', 5)->has('leads', 2)
        ->where('leads', fn ($leads) => collect($leads)->contains(fn ($l) => $l['title'] === 'Wedding hair for 6' && $l['source'] === 'quote' && $l['value_cents'] === 450000 && $l['stage'] !== null)));
    $won = ($this->in)(fn () => Stage::query()->where('name', 'Won')->value('id'));
    $this->actingAs($this->owner)->post("{$this->base}/{$this->wedding}/move", ['stage' => $won])->assertSessionHas('status', 'Lead moved.');
    $this->actingAs($this->owner)->post("{$this->base}/{$this->wedding}/assign", ['member' => $this->zanele])->assertSessionHas('status', 'Lead assigned.');
    $this->actingAs($this->owner)->post("{$this->base}/{$this->wedding}/follow-up", ['at' => '2027-10-01T09:00'])->assertSessionHas('status', 'Follow-up set.');
    $this->actingAs($this->owner)->post("{$this->base}/{$this->wedding}/note", ['text' => 'Wants a trial first'])->assertSessionHas('status', 'Note added.');
    $this->actingAs($this->owner)->get("{$this->base}/{$this->wedding}")->assertInertia(fn (Assert $p) => $p->component('Crm::Lead')
        ->where('lead.contact.name', 'Sipho')->where('lead.stage', $won)->where('lead.closed', true)->where('lead.assigned_to', $this->zanele)->where('lead.follow_up', '2027-10-01T09:00')
        ->where('history.0.event', 'note')->where('history.0.detail', 'Wants a trial first')->has('history', 5));
});

it('lets the owner rename stages, and keeps a branch manager to their branch’s leads', function () {
    $stages = ($this->in)(fn () => Stage::query()->orderBy('position')->get(['id', 'name', 'kind'])->toArray());
    $stages[0]['name'] = 'Enquiry';
    $this->actingAs($this->owner)->put("{$this->base}/stages", ['stages' => $stages])->assertSessionHas('status', 'Stages saved.');
    expect(($this->in)(fn () => Stage::query()->orderBy('position')->value('name')))->toBe('Enquiry');

    $this->actingAs($this->manager)->get($this->base)->assertInertia(fn (Assert $p) => $p->has('leads', 1)->where('leads.0.title', 'Nails for a party'));
    $this->actingAs($this->manager)->get("{$this->base}/{$this->wedding}")->assertNotFound();
    $this->actingAs($this->manager)->put("{$this->base}/stages", ['stages' => $stages])->assertSessionHasErrors(['stages' => 'Only someone who looks after every branch can change the stages.']);
});

it('removes an empty stage, but not one that still has leads', function () {
    $stages = ($this->in)(fn () => Stage::query()->orderBy('position')->get(['id', 'name', 'kind'])->toArray());
    $without = fn (string $name) => array_values(array_filter($stages, fn ($s) => $s['name'] !== $name));
    $this->actingAs($this->owner)->put("{$this->base}/stages", ['stages' => $without('New')])->assertSessionHasErrors(['stages' => '“New” still has leads — move them first.']);
    $this->actingAs($this->owner)->put("{$this->base}/stages", ['stages' => $without('Quoted')])->assertSessionHas('status', 'Stages saved.');
    expect(($this->in)(fn () => Stage::query()->orderBy('position')->pluck('name')->all()))->toBe(['New', 'Contacted', 'Won', 'Lost']);
});

it('shows open leads by default and won or lost ones on request; adds a lead from a contact', function () {
    $won = ($this->in)(fn () => Stage::query()->where('name', 'Won')->value('id'));
    ($this->in)(fn () => app(Leads::class)->move(Lead::query()->find($this->wedding), $won, 'Thandi'));
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn ($p) => $p->where('closed', false)->has('leads', 1)->where('leads.0.title', 'Nails for a party'));
    $this->actingAs($this->owner)->get("{$this->base}?show=closed")->assertInertia(fn ($p) => $p->where('closed', true)->has('leads', 1)->where('leads.0.title', 'Wedding hair for 6'));

    $sipho = ($this->in)(fn () => Contact::query()->where('name', 'Sipho')->value('id'));
    $this->actingAs($this->owner)->post($this->base, ['contact' => $sipho, 'title' => 'Bridal trial', 'value' => '850'])->assertRedirect();
    expect(($this->in)(fn () => Lead::query()->where('title', 'Bridal trial')->value('value_cents')))->toBe(85000);
});
