<?php

declare(strict_types=1);

namespace Wurk\Portal\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Contracts\Verification;
use Wurk\Portal\Support\Handoff;
use Wurk\Portal\Support\Memberships;
use Wurk\Referrals\Contracts\CustomerRewards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * My WURK (Sprint 13.8): a customer's businesses in one place. Each card is built inside that business's own context;
 * nothing is shown with, mixed into or totalled across another business.
 */
final class MyWurkController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private Memberships $memberships) {}

    public function show(Request $request): Response
    {
        $me = Auth::id();
        if ($me === null) {
            return Inertia::render('Portal::MyWurk/SignIn', ['sent' => $request->session()->get('my.masked')]);
        }
        $cards = [];
        foreach ($this->memberships->businesses((string) $me) as $tenantId) {
            $tenant = $this->tenants->find($tenantId);
            if ($tenant === null || ! $tenant->isActive()) {
                continue;
            }
            $card = $this->context->run($tenant, function () use ($me) { // this business's data only
                $contact = DB::connection($this->context->connectionName())->table('portal_customers')->where('tenant_id', $this->context->id())->where('identity_id', (string) $me)->value('contact_id');
                if ($contact === null) {
                    return null;
                }
                $current = $this->context->current();
                $rewards = app(CustomerRewards::class)->forContact((string) $contact);
                $latest = app(ContactBook::class)->history((string) $contact, ['order', 'booking', 'class', 'sale'], 1)[0] ?? null;

                return ['handle' => $current->handle(), 'business' => $current->displayName(),
                    'wallet_cents' => $rewards['wallet_cents'] ?? null, 'pending_cents' => $rewards['pending_cents'] ?? null,
                    'latest' => $latest === null ? null : $latest['summary'].' · '.CarbonImmutable::instance($latest['at'])->setTimezone($current->timezone())->format('j M Y')];
            });
            if ($card !== null) {
                $cards[] = $card;
            }
        }

        return Inertia::render('Portal::MyWurk/Home', ['cards' => $cards]);
    }

    public function code(Request $request): RedirectResponse
    {
        $contact = trim((string) $request->validate(['contact' => ['required', 'string', 'max:120']], ['contact.required' => 'Enter your phone number or email.'])['contact']);
        $sent = app(Verification::class)->send($contact, 'login', $request->ip()); // only existing WURK IDs receive one
        $request->session()->put('my.contact', $contact);
        $request->session()->put('my.masked', $sent['masked']);

        return back()->with('status', 'If you have a WURK account, we sent a code to '.$sent['masked'].'.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:10']])['code'];
        $contact = (string) $request->session()->get('my.contact', '');
        $identity = $contact === '' ? null : app(Accounts::class)->identityFor($contact);
        if ($identity === null || ! app(Verification::class)->confirm($contact, trim($code), 'login')) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right, or it has expired. Ask for a new one.']);
        }
        $request->session()->forget(['my.contact', 'my.masked']);

        return app(Accounts::class)->signIn($request, $identity, '/');
    }

    public function password(Request $request): RedirectResponse
    {
        $d = $request->validate(['identifier' => ['required', 'string', 'max:160'], 'password' => ['required', 'string', 'max:128']]);
        $identity = app(Accounts::class)->checkPassword($d['identifier'], $d['password'], $request->ip())
            ?? throw ValidationException::withMessages(['password' => 'That email/number and password don’t match.']);

        return app(Accounts::class)->signIn($request, $identity, '/');
    }

    /** "Open": to that business's portal, already signed in (a one-time pass). */
    public function open(Request $request, string $handle): RedirectResponse
    {
        $me = (string) Auth::id();
        $tenant = $this->tenants->findByHandle($handle);
        abort_if($tenant === null || ! $tenant->isActive() || ! in_array($tenant->tenantId(), $this->memberships->businesses($me), true), 404);
        $pass = app(Handoff::class)->issue($me, $tenant->tenantId());

        return redirect()->away($request->getScheme().'://'.$handle.'.'.config('wurk.domains.root').'/account/handoff?pass='.rawurlencode($pass));
    }

    public function hide(string $handle): RedirectResponse
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);
        $this->memberships->hide((string) Auth::id(), $tenant->tenantId());

        return back()->with('status', 'Removed from My WURK. Your account with them is unchanged.');
    }

    public function signOut(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
