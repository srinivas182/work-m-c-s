<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Models\Contact;
use Wurk\Crm\Support\Phone;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->biz = $this->createTenant('glow-salon');
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->biz, $fn);
    $this->remember = fn (string $name, ?string $phone, ?string $email, string $type, string $id, ?string $consent = null) => ($this->in)(
        fn () => app(ContactBook::class)->remember($name, $phone, $email, $type, $id, null, "{$type} {$id}", $consent));
});

it('writes South African numbers one way', function () {
    expect(Phone::normalise('082 555 0101'))->toBe('+27825550101')->and(Phone::normalise('+27 82 555 0101'))->toBe('+27825550101')
        ->and(Phone::normalise('27825550101'))->toBe('+27825550101')->and(Phone::normalise('+44 20 7946 0000'))->toBe('+442079460000')->and(Phone::normalise(''))->toBeNull();
});

it('keeps one contact per person, matched by exact phone or email, and links what they did', function () {
    $sipho = ($this->remember)('Sipho', '082 555 0101', null, 'booking', 'b1');
    expect(($this->remember)('Sipho M', '+27 82 555 0101', 'Sipho@Example.com', 'order', 'o1'))->toBe($sipho) // same phone; email filled in
        ->and(($this->remember)('S. Mokoena', null, 'sipho@example.com', 'class', 'c1'))->toBe($sipho)       // same email
        ->and(($this->remember)('Lerato', '083 000 0000', null, 'booking', 'b2'))->not->toBe($sipho);       // someone else

    ($this->in)(function () use ($sipho) {
        $c = Contact::query()->find($sipho);
        expect($c->name)->toBe('Sipho')->and($c->email)->toBe('sipho@example.com')->and(Contact::query()->count())->toBe(2)
            ->and(DB::connection(app(TenantContext::class)->connectionName())->table('contact_links')->where('contact_id', $sipho)->pluck('source_type')->sort()->values()->all())->toBe(['booking', 'class', 'order']);
    });
});

it('records marketing consent once, with where it was given', function () {
    $id = ($this->remember)('Sipho', '0825550101', null, 'form', 'f1', 'Website form');
    ($this->remember)('Sipho', '0825550101', null, 'chat', 'ch1', 'Chat');
    ($this->in)(fn () => expect(Contact::query()->find($id)->marketing_consent_source)->toBe('Website form'));
});

it('leads a merged contact to the one it joined', function () {
    $keep = ($this->remember)('Sipho', '0825550101', null, 'booking', 'b1');
    $old = ($this->remember)('Sipho (work)', null, 'sipho@work.co.za', 'order', 'o1');
    ($this->in)(fn () => Contact::query()->whereKey($old)->update(['merged_into' => $keep]));
    expect(($this->remember)('Sipho', null, 'sipho@work.co.za', 'order', 'o2'))->toBe($keep);
});
