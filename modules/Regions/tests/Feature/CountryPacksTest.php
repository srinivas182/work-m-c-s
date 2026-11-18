<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Regions\Contracts\Countries;
use Wurk\Regions\Models\TaxRate;

/*
 * Sprint 25.1: country packs. Each country starts a business with its currency, money format, tax (with dated,
 * confirmed rates and levies), phone format, languages and providers. HQ edits packs in Admin → Countries; a
 * country opens for sign-up only with a confirmed rate in force, and a new rate applies from its date.
 */

uses(InteractsWithIdentity::class);

beforeEach(function () {
    $this->hq = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $this->countries = app(Countries::class);
});

it('ships eight packs, South Africa confirmed and open, the rest waiting for HQ to confirm their rates', function () {
    $za = $this->countries->pack('za');
    expect($za)->toMatchArray(['code' => 'ZA', 'enabled' => true, 'currency' => 'ZAR', 'currency_symbol' => 'R', 'currency_decimals' => 2, 'thousands_separator' => ' ',
        'phone_prefix' => '27', 'phone_digits' => 9, 'returns_template' => 'za-cpa'])
        ->and($za['tax'])->toMatchArray(['name' => 'VAT', 'rate_bp' => 1500, 'total_bp' => 1500, 'confirmed' => true]);

    $gh = $this->countries->pack('GH');
    expect($gh['tax']['total_bp'])->toBe(2000)->and($gh['tax']['levies'])->toBe([['name' => 'NHIL', 'bp' => 250], ['name' => 'GETFund levy', 'bp' => 250]])
        ->and($gh['tax']['confirmed'])->toBeFalse()->and($gh['enabled'])->toBeFalse();
    expect($this->countries->pack('CI'))->toMatchArray(['currency' => 'XOF', 'currency_decimals' => 0, 'symbol_after' => true, 'languages' => ['fr']])
        ->and($this->countries->pack('KE')['payment_providers'])->toContain('mpesa')
        ->and($this->countries->find('NG')['vat_bp'])->toBe(750);
    expect(array_column($this->countries->enabled(), 'code'))->toBe(['ZA']);
});

it('lists the countries for HQ and shows a pack with its rates', function () {
    $this->actingAs($this->hq)->get($this->admin('/countries'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Regions::Countries')->where('countries.0.code', 'ZA')->where('countries.0.tax.total', 15));
    $this->actingAs($this->hq)->get($this->admin('/countries/gh'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Regions::Country')->where('country.code', 'GH')->where('rates.0.total', 20)->where('rates.0.confirmed', false));
});

it('opens a country for sign-up only once its rate is confirmed, and records who changed what', function () {
    $this->actingAs($this->hq)->from($this->admin('/countries/ke'))->put($this->admin('/countries/ke'), ['enabled' => true])
        ->assertSessionHasErrors(['enabled' => 'Confirm Kenya’s tax rate before opening it for sign-up.']);
    $rate = TaxRate::query()->where('country_code', 'KE')->firstOrFail();
    $this->actingAs($this->hq)->post($this->admin("/countries/ke/rates/{$rate->id}/confirm"))->assertSessionHas('status', 'Rate confirmed.');
    $this->actingAs($this->hq)->put($this->admin('/countries/ke'), ['enabled' => true, 'currency_symbol' => 'KSh', 'languages' => ['sw', 'en']])->assertSessionHasNoErrors();

    expect($this->countries->pack('KE'))->toMatchArray(['enabled' => true, 'languages' => ['sw', 'en']])
        ->and(array_column($this->countries->enabled(), 'code'))->toBe(['KE', 'ZA']);
    $this->assertDatabaseHas('audit_events', ['action' => 'country.tax_rate_confirmed', 'subject_id' => 'KE']);
    $this->assertDatabaseHas('audit_events', ['action' => 'country.updated', 'subject_id' => 'KE']);
});

it('applies a new rate from its date once confirmed; an unconfirmed or future rate changes nothing', function () {
    $this->actingAs($this->hq)->post($this->admin('/countries/za/rates'), ['rate' => '16', 'starts_on' => '2027-04-01', 'note' => 'Budget 2027'])
        ->assertSessionHas('status', 'Rate added. Confirm it to use it.');
    $new = TaxRate::query()->where('country_code', 'ZA')->where('starts_on', '2027-04-01')->firstOrFail();
    $april = CarbonImmutable::parse('2027-04-02', 'Africa/Johannesburg');

    expect($this->countries->taxOn('ZA', $april)['rate_bp'])->toBe(1500); // added, not yet confirmed
    $this->actingAs($this->hq)->post($this->admin("/countries/za/rates/{$new->id}/confirm"));
    expect($this->countries->taxOn('ZA', $april)['rate_bp'])->toBe(1600)
        ->and($this->countries->taxOn('ZA', CarbonImmutable::parse('2027-03-31'))['rate_bp'])->toBe(1500) // before it starts
        ->and($this->countries->find('ZA')['vat_bp'])->toBe(1500); // today

    $this->actingAs($this->hq)->delete($this->admin("/countries/za/rates/{$new->id}"))
        ->assertSessionHasErrors(['rate' => 'A confirmed rate stays — add a new rate from a later date instead.']);
    $this->actingAs($this->hq)->post($this->admin('/countries/za/rates'), ['rate' => '15', 'starts_on' => '2027-04-01'])
        ->assertSessionHasErrors(['starts_on' => 'There’s already a rate starting that day.']);
});

it('adds levies to a rate and counts them in the total', function () {
    $this->actingAs($this->hq)->post($this->admin('/countries/gh/rates'), ['rate' => '15', 'starts_on' => '2027-01-01', 'levies' => [['name' => 'NHIL', 'rate' => '2.5'], ['name' => 'COVID-19 levy', 'rate' => '1']]])
        ->assertSessionHasNoErrors();
    $rate = TaxRate::query()->where('country_code', 'GH')->where('starts_on', '2027-01-01')->firstOrFail();
    expect($rate->totalBp())->toBe(1850)->and($rate->levies)->toEqual([['name' => 'NHIL', 'bp' => 250], ['name' => 'COVID-19 levy', 'bp' => 100]]); // MySQL's JSON may reorder keys
});

it('keeps Admin → Countries to HQ staff who manage countries', function () {
    $support = $this->staffWithRole('support', 'help@wurk.co.za');
    $this->actingAs($support)->get($this->admin('/countries'))->assertForbidden();
    $this->actingAs($support)->post($this->admin('/countries/za/rates'), ['rate' => '20', 'starts_on' => '2027-01-01'])->assertForbidden();
});
