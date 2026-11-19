<?php

declare(strict_types=1);

namespace Wurk\Trust\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Trust\Models\BusinessVerification;
use Wurk\Trust\Support\Verifications;

/** Admin → Verification (25.4): HQ's queue of identity and registry checks, the documents, and decisions with reasons. */
final class VerificationController
{
    public function __construct(private Verifications $verifications, private TenantDirectory $tenants) {}

    public function index(): Response
    {
        Gate::authorize('verification.review');
        $rows = BusinessVerification::query()->where('identity_status', 'pending')->orWhere('registry_status', 'pending')
            ->orderBy('identity_submitted_at')->orderBy('registry_submitted_at')->limit(200)->get();

        return Inertia::render('Trust::Admin/Queue', ['queue' => $rows->map(fn (BusinessVerification $v) => [
            'tenant' => $v->tenant_id, 'business' => $this->tenants->find($v->tenant_id)?->displayName() ?? '—',
            'identity' => $v->identity_status === 'pending' ? $v->identity_submitted_at?->toDateString() : null,
            'registry' => $v->registry_status === 'pending' ? $v->registry_submitted_at?->toDateString() : null,
        ])->values()->all()]);
    }

    public function show(string $tenant): Response
    {
        Gate::authorize('verification.review');
        $v = BusinessVerification::query()->findOrFail($tenant);
        $business = $this->tenants->find($tenant);

        return Inertia::render('Trust::Admin/Check', [
            'tenant' => $tenant, 'business' => $business?->displayName() ?? '—', 'handle' => $business?->handle(),
            'identity' => ['status' => $v->identity_status, 'submitted' => $v->identity_submitted_at?->toDateString(), 'reason' => $v->identity_reason],
            'registry' => ['status' => $v->registry_status, 'number' => $v->registry_number, 'reason' => $v->registry_reason],
            'documents' => array_column($v->documents ?? [], 'kind'),
            'badge' => $this->verifications->for($tenant),
        ]);
    }

    public function document(Request $request, string $tenant, string $kind): HttpResponse
    {
        Gate::authorize('verification.review');
        $doc = $this->verifications->document($tenant, $kind, (string) $request->user()?->getAuthIdentifier());

        return response($doc['content'], 200, ['Content-Type' => $doc['mime'], 'Content-Disposition' => 'inline', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff']);
    }

    public function decide(Request $request, string $tenant, string $check): RedirectResponse
    {
        Gate::authorize('verification.review');
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:300']]);
        $by = (string) $request->user()?->getAuthIdentifier();
        match ($check) {
            'identity' => $this->verifications->decideIdentity($tenant, (bool) $data['approve'], $data['reason'] ?? null, $by),
            'registry' => $this->verifications->decideRegistry($tenant, (bool) $data['approve'], $data['reason'] ?? null, $by),
            default => abort(404),
        };

        return back()->with('status', $data['approve'] ? __('Approved.') : __('Refused — the owner sees why.'));
    }
}
