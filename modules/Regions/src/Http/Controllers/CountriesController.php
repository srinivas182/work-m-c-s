<?php

declare(strict_types=1);

namespace Wurk\Regions\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\I18n\Locales;
use Wurk\Regions\Contracts\Countries;
use Wurk\Regions\Models\Country;
use Wurk\Regions\Models\TaxRate;

/**
 * Admin → Countries (25.1): each country's pack, its tax rates by date, and whether businesses can sign up there.
 * A rate is used once HQ confirms it, and a country opens for sign-up only with a confirmed rate in force.
 */
final class CountriesController
{
    public const PROVIDERS = ['payfast', 'yoco', 'ozow', 'peach', 'paystack', 'flutterwave', 'mpesa', 'mtn_momo', 'airtel_money', 'eft'];

    public const RETURNS_TEMPLATES = ['za-cpa' => 'South Africa (Consumer Protection Act)', 'generic' => 'General'];

    public function __construct(private Countries $countries, private AuditLog $audit) {}

    public function index(): Response
    {
        Gate::authorize('countries.manage');

        return Inertia::render('Regions::Countries', [
            'countries' => Country::query()->orderByDesc('enabled')->orderBy('name')->get()->map(function (Country $c) {
                $tax = $this->countries->taxOn($c->code, CarbonImmutable::now($c->timezone));

                return ['code' => $c->code, 'name' => $c->name, 'currency' => $c->currency, 'phone_prefix' => $c->phone_prefix, 'enabled' => $c->enabled,
                    'tax' => $tax === null ? null : ['name' => $tax['name'], 'total' => $tax['total_bp'] / 100, 'confirmed' => $tax['confirmed']]];
            })->all(),
        ]);
    }

    public function show(string $code): Response
    {
        Gate::authorize('countries.manage');
        $pack = $this->countries->pack($code) ?? abort(404);

        return Inertia::render('Regions::Country', [
            'country' => $pack,
            'rates' => TaxRate::query()->where('country_code', $pack['code'])->orderByDesc('starts_on')->get()->map(fn (TaxRate $r) => [
                'id' => $r->id, 'rate' => $r->rate_bp / 100, 'levies' => array_map(fn (array $l) => ['name' => $l['name'], 'rate' => $l['bp'] / 100], $r->levies ?? []),
                'total' => $r->totalBp() / 100, 'starts_on' => $r->startsOn(), 'note' => $r->note, 'confirmed' => $r->confirmed_at !== null,
                'confirmed_at' => $r->confirmed_at?->toDateString(),
            ])->all(),
            'providers' => self::PROVIDERS,
            'returnsTemplates' => self::RETURNS_TEMPLATES,
            'languages' => array_keys(Locales::SUPPORTED),
        ]);
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        Gate::authorize('countries.manage');
        $country = Country::query()->findOrFail(strtoupper($code));
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'currency_symbol' => ['sometimes', 'required', 'string', 'max:8'],
            'currency_decimals' => ['sometimes', 'required', 'integer', 'in:0,2,3'],
            'decimal_separator' => ['sometimes', 'required', 'string', Rule::in(['.', ','])],
            'thousands_separator' => ['sometimes', 'required', 'string', Rule::in([' ', ',', '.', "\u{2009}", "\u{202F}"])],
            'symbol_after' => ['sometimes', 'required', 'boolean'],
            'tax_name' => ['sometimes', 'required', 'string', 'max:20'],
            'prices_include_tax' => ['sometimes', 'required', 'boolean'],
            'phone_digits' => ['sometimes', 'required', 'integer', 'between:6,12'],
            'week_starts_on' => ['sometimes', 'required', 'integer', 'in:1,6,7'],
            'languages' => ['sometimes', 'array', 'min:1'],
            'languages.*' => ['string', Rule::in(array_keys(Locales::SUPPORTED))],
            'payment_providers' => ['sometimes', 'array'],
            'payment_providers.*' => ['string', Rule::in(self::PROVIDERS)],
            'sms_provider' => ['sometimes', 'nullable', 'string', 'max:30'],
            'ussd_provider' => ['sometimes', 'nullable', 'string', 'max:30'],
            'returns_template' => ['sometimes', 'required', Rule::in(array_keys(self::RETURNS_TEMPLATES))],
        ]);
        if ($data['enabled'] && ! ($this->countries->taxOn($country->code, CarbonImmutable::now($country->timezone))['confirmed'] ?? false)) {
            return back()->withErrors(['enabled' => __('Confirm :country’s tax rate before opening it for sign-up.', ['country' => $country->name])]);
        }
        $before = $country->only(array_keys($data));
        $country->update($data);
        $this->audit->record('country.updated', 'country', $country->code, ['before' => $before, 'after' => $country->only(array_keys($data))], ['platform' => true]);

        return back()->with('status', __(':country updated.', ['country' => $country->name]));
    }

    /** A new rate from a date (a budget change). It starts unconfirmed; earlier invoices keep their rate. */
    public function addRate(Request $request, string $code): RedirectResponse
    {
        Gate::authorize('countries.manage');
        $country = Country::query()->findOrFail(strtoupper($code));
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'between:0,50'],
            'starts_on' => ['required', 'date_format:Y-m-d', Rule::unique('country_tax_rates', 'starts_on')->where('country_code', $country->code)],
            'note' => ['nullable', 'string', 'max:200'],
            'levies' => ['array', 'max:4'],
            'levies.*.name' => ['required', 'string', 'max:40'],
            'levies.*.rate' => ['required', 'numeric', 'between:0,20'],
        ], ['starts_on.unique' => __('There’s already a rate starting that day.')]);
        $rate = TaxRate::query()->create(['country_code' => $country->code, 'rate_bp' => (int) round((float) $data['rate'] * 100), 'starts_on' => $data['starts_on'],
            'note' => $data['note'] ?? null, 'created_by' => (string) $request->user()?->getAuthIdentifier(),
            'levies' => array_values(array_map(fn (array $l) => ['name' => trim($l['name']), 'bp' => (int) round((float) $l['rate'] * 100)], $data['levies'] ?? [])) ?: null]);
        $this->audit->record('country.tax_rate_added', 'country', $country->code, ['rate_bp' => $rate->rate_bp, 'levies' => $rate->levies, 'starts_on' => $data['starts_on']], ['platform' => true]);

        return back()->with('status', __('Rate added. Confirm it to use it.'));
    }

    public function confirmRate(Request $request, string $code, int $rate): RedirectResponse
    {
        Gate::authorize('countries.manage');
        $row = TaxRate::query()->where('country_code', strtoupper($code))->findOrFail($rate);
        if ($row->confirmed_at === null) {
            $row->update(['confirmed_at' => now(), 'confirmed_by' => (string) $request->user()?->getAuthIdentifier()]);
            $this->audit->record('country.tax_rate_confirmed', 'country', $row->country_code, ['rate_bp' => $row->rate_bp, 'levies' => $row->levies, 'starts_on' => $row->startsOn()], ['platform' => true]);
        }

        return back()->with('status', __('Rate confirmed.'));
    }

    /** Only a rate nobody has confirmed can go: a confirmed rate may already be on invoices. */
    public function deleteRate(string $code, int $rate): RedirectResponse
    {
        Gate::authorize('countries.manage');
        $row = TaxRate::query()->where('country_code', strtoupper($code))->findOrFail($rate);
        if ($row->confirmed_at !== null) {
            return back()->withErrors(['rate' => __('A confirmed rate stays — add a new rate from a later date instead.')]);
        }
        $row->delete();
        $this->audit->record('country.tax_rate_deleted', 'country', $row->country_code, ['rate_bp' => $row->rate_bp, 'starts_on' => $row->startsOn()], ['platform' => true]);

        return back()->with('status', __('Rate removed.'));
    }
}
