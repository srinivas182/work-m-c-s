<?php

declare(strict_types=1);

namespace Wurk\Payments\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Gateways\Flutterwave;
use Wurk\Payments\Gateways\Paystack;
use Wurk\Payments\Gateways\Yoco;
use Wurk\Payments\Models\PlatformPaymentAccount;
use Wurk\Payments\Support\KeyFields;
use Wurk\Payments\Support\PlatformMethods;

/**
 * Super admin → Payment methods: which methods businesses may offer, and WURK's own accounts (for
 * businesses paying WURK invoices) — each enabled or disabled, test or live, keys kept per mode.
 */
final class PaymentMethodsController
{
    public function __construct(private PlatformMethods $platform, private AuditLog $audit) {}

    public function index(): Response
    {
        Gate::authorize('providers.manage');
        $accounts = PlatformPaymentAccount::query()->get()->keyBy('provider');

        return Inertia::render('Payments::Admin/PaymentMethods', ['methods' => array_map(fn (string $p) => [
            'provider' => $p, 'name' => PlatformMethods::NAMES[$p], 'available' => $this->platform->available($p),
            // Mobile money is only for businesses' own tills and wallets; WURK's own is Flutterwave (Sprint 22.4).
            'wurk_account' => in_array($p, PlatformMethods::WURK, true), 'countries' => PlatformMethods::COUNTRIES[$p] ?? null,
            'business_offer' => ! in_array($p, PlatformMethods::WURK_ONLY, true),
            'wurk' => ($a = $accounts[$p] ?? null) === null ? null : [
                'active' => $a->active, 'mode' => $a->mode, 'label' => $a->label, 'has_test' => $a->hasKeys('test'), 'has_live' => $a->hasKeys('live'),
            ],
        ], [...PlatformMethods::ALL, ...PlatformMethods::WURK_ONLY])]);
    }

    /** Whether businesses may offer this method (platform-wide). */
    public function availability(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('providers.manage');
        $on = (bool) $request->validate(['available' => ['required', 'boolean']])['available'];
        $this->platform->set($provider, $on);
        $this->audit->record('payments.method_availability', 'payment_method', $provider, ['available' => $on]);

        return back()->with('status', PlatformMethods::NAMES[$provider].($on ? ' can be offered by businesses.' : ' is switched off for every business.'));
    }

    /** WURK's keys for one mode (test or live). */
    public function keys(Request $request, string $provider, string $mode): RedirectResponse
    {
        Gate::authorize('providers.manage');
        $keys = array_map(fn ($v) => trim((string) $v), $request->validate(KeyFields::rules($provider, $mode), KeyFields::messages($mode, $provider)));
        $account = PlatformPaymentAccount::query()->find($provider) ?? new PlatformPaymentAccount(['provider' => $provider, 'mode' => $mode]);
        if ($provider === 'flutterwave' && ! Flutterwave::check(new PlatformPaymentAccount(['provider' => 'flutterwave', 'mode' => $mode, $mode.'_credentials' => $keys]))) {
            throw ValidationException::withMessages(['secret_key' => 'Flutterwave didn’t accept that key. Check it and try again.']);
        }
        if ($provider === 'paystack' && ! Paystack::check(new PlatformPaymentAccount(['provider' => 'paystack', 'mode' => $mode, $mode.'_credentials' => $keys]))) {
            throw ValidationException::withMessages(['secret_key' => 'Paystack didn’t accept that key. Check it (Settings → API Keys & Webhooks in Paystack) and try again.']);
        }
        if ($provider === 'yoco') { // registering our notification address tests the key, and gives this mode its own signing secret
            $probe = new PlatformPaymentAccount(['provider' => 'yoco', 'mode' => $mode, $mode.'_credentials' => $keys]);
            $hook = Yoco::register($probe, 'https://'.config('wurk.domains.app').'/payments/notify/yoco');
            if ($hook === null) {
                throw ValidationException::withMessages(['secret_key' => 'Yoco didn’t accept that key. Check it and try again.']);
            }
            $keys['webhook_id'] = $hook[0];
            $keys['webhook_secret'] = $hook[1];
        }
        $account->fill([$mode.'_credentials' => $keys, 'label' => KeyFields::label($provider, $keys)])->save();
        $this->audit->record('payments.wurk_keys_saved', 'payment_method', $provider, ['mode' => $mode]);

        return back()->with('status', PlatformMethods::NAMES[$provider].' '.$mode.' keys saved.');
    }

    /** Enable or disable WURK's account, and choose test or live (live only once live keys are saved). */
    public function settings(Request $request, string $provider): RedirectResponse
    {
        Gate::authorize('providers.manage');
        $data = $request->validate(['active' => ['required', 'boolean'], 'mode' => ['required', 'in:test,live']]);
        $account = PlatformPaymentAccount::query()->find($provider);
        if (($data['active'] || $data['mode'] === 'live') && ($account === null || ! $account->hasKeys($data['mode']))) {
            throw ValidationException::withMessages(['mode' => 'Save the '.$data['mode'].' keys first.']);
        }
        $account?->update(['active' => (bool) $data['active'], 'mode' => $data['mode']]);
        $this->audit->record('payments.wurk_method_settings', 'payment_method', $provider, $data);

        return back()->with('status', PlatformMethods::NAMES[$provider].($data['active'] ? ' is on in '.$data['mode'].' mode.' : ' is off.'));
    }
}
