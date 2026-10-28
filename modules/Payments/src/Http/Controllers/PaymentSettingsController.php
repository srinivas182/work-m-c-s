<?php

declare(strict_types=1);

namespace Wurk\Payments\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Gateways\Yoco;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Support\KeyFields;
use Wurk\Payments\Support\PlatformMethods;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Owners: Settings → Payments — from the methods WURK allows, each enabled or disabled, test or live, with
 * test and live keys kept separately. Keys are never shown again.
 */
final class PaymentSettingsController
{
    public function __construct(private TenantContext $context, private AuditLog $audit, private PlatformMethods $platform) {}

    public function show(): Response
    {
        $accounts = PaymentAccount::query()->get()->keyBy('provider');

        return Inertia::render('Payments::Settings', ['methods' => array_map(fn (string $p) => [
            'provider' => $p, 'name' => PlatformMethods::NAMES[$p], 'allowed' => $this->platform->available($p),
            'account' => ($a = $accounts[$p] ?? null) === null ? null : [
                'active' => $a->active, 'mode' => $a->mode, 'label' => $a->label, 'has_test' => $a->hasKeys('test'), 'has_live' => $a->hasKeys('live'),
            ],
        ], PlatformMethods::ALL)]);
    }

    /** Keys for one mode (test or live). */
    public function keys(Request $request, string $provider, string $mode): RedirectResponse
    {
        abort_unless($this->platform->available($provider), 404);
        $keys = array_map(fn ($v) => trim((string) $v), $request->validate(KeyFields::rules($provider, $mode), KeyFields::messages($mode)));
        if ($provider === 'yoco') { // registering our notification address tests the key, and gives this mode its own signing secret
            $probe = new PaymentAccount(['provider' => 'yoco', 'mode' => $mode, $mode.'_credentials' => $keys]);
            $hook = Yoco::register($probe, 'https://'.$this->context->current()->handle().'.'.config('wurk.domains.root').'/payments/notify/yoco');
            if ($hook === null) {
                throw ValidationException::withMessages(['secret_key' => 'Yoco didn’t accept that key. Check it and try again.']);
            }
            $keys['webhook_id'] = $hook[0];
            $keys['webhook_secret'] = $hook[1];
        }
        $account = PaymentAccount::query()->where('provider', $provider)->first() ?? new PaymentAccount(['provider' => $provider, 'mode' => $mode, 'active' => false]);
        $account->fill([$mode.'_credentials' => $keys, 'label' => KeyFields::label($provider, $keys)])->save();
        $this->audit->record('payments.keys_saved', 'payment_account', $provider, ['mode' => $mode]);

        return back()->with('status', PlatformMethods::NAMES[$provider].' '.$mode.' keys saved.');
    }

    /** On or off, and test or live (live only with live keys). */
    public function settings(Request $request, string $provider): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean'], 'mode' => ['required', 'in:test,live']]);
        $account = PaymentAccount::query()->where('provider', $provider)->first();
        if ($data['active'] && ! $this->platform->available($provider)) {
            throw ValidationException::withMessages(['mode' => 'WURK has switched '.PlatformMethods::NAMES[$provider].' off for now.']);
        }
        if (($data['active'] || $data['mode'] === 'live') && ($account === null || ! $account->hasKeys($data['mode']))) {
            throw ValidationException::withMessages(['mode' => 'Save the '.$data['mode'].' keys first.']);
        }
        $account?->update(['active' => (bool) $data['active'], 'mode' => $data['mode']]);
        $this->audit->record('payments.method_settings', 'payment_account', $provider, $data);
        $name = PlatformMethods::NAMES[$provider];

        return back()->with('status', ! $data['active'] ? "{$name} is off." : ($data['mode'] === 'test' ? "{$name} is on in test mode — no real money moves." : "{$name} is live."));
    }

    public function disconnect(string $provider): RedirectResponse
    {
        $account = PaymentAccount::query()->where('provider', $provider)->firstOrFail();
        if ($provider === 'yoco') {
            foreach (['test', 'live'] as $mode) { // best effort: stop Yoco calling us
                $keys = $mode === 'live' ? $account->live_credentials : $account->test_credentials;
                if (! empty($keys['webhook_id'])) {
                    try {
                        Http::timeout(10)->withToken((string) ($keys['secret_key'] ?? ''))->delete(Yoco::API.'/webhooks/'.rawurlencode((string) $keys['webhook_id']));
                    } catch (Throwable) {
                    }
                }
            }
        }
        $account->delete();
        $this->audit->record('payments.disconnected', 'payment_account', $provider, []);

        return back()->with('status', PlatformMethods::NAMES[$provider].' is disconnected. Customers can’t pay with it any more.');
    }
}
