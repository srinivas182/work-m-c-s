<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\LeadBook;
use Wurk\Crm\Mail\FollowUpMail;
use Wurk\Crm\Models\Lead;
use Wurk\Crm\Models\Stage;
use Wurk\Crm\Support\Leads;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->biz = $this->createTenant('glow-salon');
    $this->zanele = $this->person(['email' => 'zanele@glow.co.za', 'phone' => '+27820000005']);
    $this->member($this->zanele, $this->biz);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->biz, $fn);
    [$this->lead, $this->memberId] = ($this->in)(function () {
        $member = app(Team::class)->add($this->zanele->id, 'Zanele', 'staff')->id;
        $contact = app(ContactBook::class)->remember('Sipho', '082 555 0101', null, 'form', 'f1', null, 'Quote request');

        return [app(LeadBook::class)->open($contact, 'Wedding hair for 6', 'quote', 450000, null, 'From the quote form'), $member];
    });
    $this->stage = fn (string $name) => ($this->in)(fn () => Stage::query()->where('name', $name)->value('id'));
});

it('starts every business with a Sales pipeline, and closes and reopens leads as they move', function () {
    ($this->in)(function () {
        expect(Stage::query()->orderBy('position')->pluck('name')->all())->toBe(['New', 'Contacted', 'Quoted', 'Won', 'Lost'])
            ->and(Stage::query()->find(Lead::query()->find($this->lead)->stage_id)->name)->toBe('New');
    });
    ($this->in)(fn () => app(Leads::class)->move(Lead::query()->find($this->lead), ($this->stage)('Won'), 'Thandi'));
    expect(($this->in)(fn () => Lead::query()->find($this->lead)->closed_at))->not->toBeNull();
    ($this->in)(fn () => app(Leads::class)->move(Lead::query()->find($this->lead), ($this->stage)('Contacted'), 'Thandi'));
    expect(($this->in)(fn () => Lead::query()->find($this->lead)->closed_at))->toBeNull()
        ->and(($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('lead_events')->orderBy('at')->orderBy('id')->pluck('event')->all()))->toBe(['created', 'moved', 'moved']);
});

it('reminds the assignee once per follow-up date, and never about closed leads', function () {
    ($this->in)(function () {
        $lead = Lead::query()->find($this->lead);
        app(Leads::class)->assign($lead, $this->memberId, 'Zanele', 'Thandi');
        app(Leads::class)->followUp($lead, now()->subHour()->toDateTimeString(), 'Thandi');
    });
    $this->artisan('crm:follow-ups')->assertSuccessful();
    Mail::assertQueued(FollowUpMail::class, fn (FollowUpMail $m) => $m->hasTo('zanele@glow.co.za') && $m->leads[0]['title'] === 'Wedding hair for 6' && $m->leads[0]['contact'] === 'Sipho');
    Mail::assertQueuedCount(1);
    $this->artisan('crm:follow-ups')->assertSuccessful();
    Mail::assertQueuedCount(1); // not again for the same date

    ($this->in)(fn () => app(Leads::class)->followUp(Lead::query()->find($this->lead), now()->subMinute()->toDateTimeString(), 'Thandi'));
    $this->artisan('crm:follow-ups')->assertSuccessful();
    Mail::assertQueuedCount(2); // a new date, a new reminder

    ($this->in)(function () {
        $lead = Lead::query()->find($this->lead);
        app(Leads::class)->followUp($lead, now()->subMinute()->toDateTimeString(), 'Thandi');
        app(Leads::class)->move($lead->refresh(), ($this->stage)('Lost'), 'Thandi');
    });
    $this->artisan('crm:follow-ups')->assertSuccessful();
    Mail::assertQueuedCount(2); // closed: no reminder
});
