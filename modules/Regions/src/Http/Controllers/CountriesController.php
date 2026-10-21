<?php

declare(strict_types=1);

namespace Wurk\Regions\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Regions\Models\Country;

final class CountriesController
{
    public function index(): Response
    {
        Gate::authorize('countries.manage');

        return Inertia::render('Regions::Countries', [
            'countries' => Country::query()->orderByDesc('enabled')->orderBy('name')->get()
                ->map(fn (Country $c) => $c->only(['code', 'name', 'currency', 'phone_prefix', 'timezone', 'enabled']) + ['vat' => $c->vat_bp / 100])->all(),
        ]);
    }

    public function update(Request $request, string $code): RedirectResponse
    {
        Gate::authorize('countries.manage');
        $country = Country::query()->findOrFail(strtoupper($code));
        $data = $request->validate(['vat' => ['required', 'numeric', 'between:0,50'], 'enabled' => ['required', 'boolean']]);
        $before = $country->only(['vat_bp', 'enabled']);
        $country->update(['vat_bp' => (int) round((float) $data['vat'] * 100), 'enabled' => (bool) $data['enabled']]);
        app(AuditLog::class)->record('country.updated', 'country', $country->code, ['before' => $before, 'after' => $country->only(['vat_bp', 'enabled'])], ['platform' => true]);

        return back()->with('status', "{$country->name} updated.");
    }
}
