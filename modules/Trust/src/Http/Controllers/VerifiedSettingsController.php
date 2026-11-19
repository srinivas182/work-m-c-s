<?php

declare(strict_types=1);

namespace Wurk\Trust\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Trust\Support\Verifications;

/** Settings → Verified business (25.4): the owner sees each check and sends what WURK needs. */
final class VerifiedSettingsController
{
    public function __construct(private Verifications $verifications, private TenantContext $context) {}

    public function index(): Response
    {
        $v = $this->verifications->current();

        return Inertia::render('Trust::Verified', [
            'badge' => $this->verifications->for($this->context->id()),
            'checks' => [
                'phone' => $this->verifications->ownerPhoneVerified(),
                'identity' => ['status' => $v->identity_status, 'reason' => $v->identity_reason, 'expires' => $v->identity_expires_at?->toDateString()],
                'registry' => ['status' => $v->registry_status, 'reason' => $v->registry_reason, 'number' => $v->registry_number],
            ],
        ]);
    }

    public function identity(Request $request): RedirectResponse
    {
        $request->validate([
            'id_document' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
            'selfie' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:8192'],
        ], ['id_document.required' => __('Add a photo or scan of your ID document.'), 'selfie.required' => __('Add a photo of yourself holding the same ID.')]);
        $this->verifications->submitIdentity($request->file('id_document'), $request->file('selfie'), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Sent. WURK checks it within two working days.'));
    }

    public function registry(Request $request): RedirectResponse
    {
        $data = $request->validate(['registry_number' => ['required', 'string', 'max:30']]);
        $this->verifications->submitRegistry($data['registry_number'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Sent. WURK checks it within two working days.'));
    }
}
