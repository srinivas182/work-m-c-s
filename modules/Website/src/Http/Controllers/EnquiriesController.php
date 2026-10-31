<?php

declare(strict_types=1);

namespace Wurk\Website\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Website\Models\Enquiry;
use Wurk\Website\Support\Pages;

/** Owners: Website → Enquiries (quote requests until the CRM). */
final class EnquiriesController
{
    public function index(Request $request, Pages $pages): Response
    {
        $site = $pages->ensureSite();
        $view = $request->query('show') === 'archived' ? 'archived' : 'open';

        return Inertia::render('Website::Enquiries', [
            'view' => $view,
            'enquiries' => Enquiry::query()->when($view === 'archived', fn ($q) => $q->where('status', 'archived'), fn ($q) => $q->where('status', '!=', 'archived'))
                ->latest('created_at')->limit(200)->get()
                ->map(fn (Enquiry $e) => [...$e->only(['id', 'name', 'phone', 'email', 'service', 'message', 'status', 'form']), 'at' => $e->created_at->toIso8601String(),
                    'answers' => $e->answers ?? [],
                    // Links into the CRM, for people allowed to see contacts and leads.
                    'contact_id' => Gate::allows('contacts.view') ? $e->contact_id : null, 'lead_id' => Gate::allows('leads.view') ? $e->lead_id : null])->all(),
            'newCount' => Enquiry::query()->where('status', 'new')->count(),
            'notifyEmail' => $site->settings['enquiry_email'] ?? '',
            'base' => route('tenant.website.enquiries', [], false),
        ]);
    }

    public function status(Request $request, string $enquiry): RedirectResponse
    {
        $status = (string) $request->validate(['status' => ['required', 'in:new,read,archived']])['status'];
        Enquiry::query()->findOrFail($enquiry)->update(['status' => $status]);

        return back();
    }

    public function settings(Request $request, Pages $pages): RedirectResponse
    {
        $email = $request->validate(['notify_email' => ['nullable', 'email', 'max:120']])['notify_email'] ?? null;
        $site = $pages->ensureSite();
        $site->update(['settings' => [...$site->settings, 'enquiry_email' => $email]]);

        return back()->with('status', $email ? "New requests will be emailed to {$email}." : 'Email notifications turned off.');
    }
}
