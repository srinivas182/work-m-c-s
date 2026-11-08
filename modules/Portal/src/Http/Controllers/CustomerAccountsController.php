<?php

declare(strict_types=1);

namespace Wurk\Portal\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Portal\Support\DefaultPasswords;
use Wurk\Tenancy\Contracts\TenantContext;

/** Settings → Customer accounts: how customers sign in, and sending someone their portal access (Sprint 13.6b). */
final class CustomerAccountsController
{
    public function __construct(private TenantContext $context, private DefaultPasswords $passwords) {}

    public function show(): Response
    {
        $tenant = $this->context->current();

        return Inertia::render('Portal::CustomerAccounts', ['passwords' => $this->passwords->allowed(), 'link' => $tenant->handle().'.'.config('wurk.domains.root').'/account']);
    }

    public function update(Request $request): RedirectResponse
    {
        $on = (bool) $request->validate(['passwords' => ['required', 'boolean']])['passwords'];
        $this->passwords->setAllowed($on);

        return back()->with('status', $on ? 'Customers can now sign in with a code or a password.' : 'Customers sign in with a code.');
    }

    /** Send a customer their portal access: a default password by SMS (uses your SMS credits) or email. */
    public function send(Request $request): RedirectResponse
    {
        $d = $request->validate(['customer' => ['required', 'string', 'max:160'], 'channel' => ['required', 'in:sms,email']], ['customer.required' => 'Enter their phone number or email.']);
        $isEmail = str_contains($d['customer'], '@');
        $found = app(ContactBook::class)->find($isEmail ? null : $d['customer'], $isEmail ? $d['customer'] : null)
            ?? throw ValidationException::withMessages(['customer' => 'No customer with that phone number or email.']);
        $result = $this->passwords->issue($found['id'], $d['channel']);

        return match ($result) {
            'sent' => back()->with('status', 'Sent '.$found['name'].' their portal access by '.($d['channel'] === 'sms' ? 'SMS' : 'email').'.'),
            'not_allowed' => throw ValidationException::withMessages(['customer' => 'Turn on passwords first.']),
            'has_password' => throw ValidationException::withMessages(['customer' => $found['name'].' already has a WURK password — they can sign in with it.']),
            default => throw ValidationException::withMessages(['customer' => $found['name'].' has no '.($d['channel'] === 'sms' ? 'phone number' : 'email').' on record.']),
        };
    }
}
