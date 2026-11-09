<?php

declare(strict_types=1);

namespace Wurk\Reports\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Reports\Support\TakingsSection;

/** Settings → VAT registration (Sprint 15.1b). */
final class VatController
{
    public function show(TakingsSection $takings): Response
    {
        return Inertia::render('Reports::Vat', [...$takings->settings(), 'rate' => $takings->vatBp() / 100]);
    }

    public function update(Request $request, TakingsSection $takings, AuditLog $audit): RedirectResponse
    {
        $d = $request->validate(['vat_registered' => ['required', 'boolean'], 'vat_number' => ['nullable', 'required_if:vat_registered,true', 'string', 'max:30']],
            ['vat_number.required_if' => 'Your VAT number.']);
        $takings->saveSettings((bool) $d['vat_registered'], $d['vat_number'] ?? null);
        $audit->record('reports.vat_registration', 'report_settings', 'vat', ['registered' => (bool) $d['vat_registered']]);

        return back()->with('status', 'Saved.');
    }
}
