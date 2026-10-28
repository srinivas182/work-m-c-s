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
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Settings → Payments — connect the business's own PayFast or Yoco account. Keys are never shown again. */
final class PaymentSettingsController
{
    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    public function show(): Response
    {
        return Inertia::render('Payments::Settings', [
            'accounts' => PaymentAccount::query()->orderBy('provider')->get()->map(fn (PaymentAccount $a) => [
                'provider' => $a->provider, 'mode' => $a->mode, 'label' => $a->label, 'active' => $a->active,
            ])->values()->all(),
        ]);
    }

    public function connectPayFast(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'regex:/^\d{6,12}$/'], 'merchant_key' => ['required', 'regex:/^[A-Za-z0-9]{10,20}$/'],
            'passphrase' => ['required', 'string', 'min:6', 'max:100'], 'mode' => ['required', 'in:test,live'],
        ], [
            'merchant_id.regex' => 'Your PayFast merchant ID is a number (Settings → Integration in PayFast).',
            'merchant_key.regex' => 'Your PayFast merchant key is letters and numbers (Settings → Integration in PayFast).',
            'passphrase.required' => 'Set a passphrase in PayFast (Settings → Integration) and enter it here — it protects your payments.',
        ]);
        PaymentAccount::query()->updateOrCreate(['provider' => 'payfast'], [
            'mode' => $data['mode'], 'active' => true, 'webhook_secret' => null, 'webhook_id' => null,
            ($data['mode'] === 'live' ? 'live_credentials' : 'test_credentials') => ['merchant_id' => $data['merchant_id'], 'merchant_key' => $data['merchant_key'], 'passphrase' => $data['passphrase']],
            'label' => 'Merchant ••••'.substr($data['merchant_id'], -4),
        ]);
        $this->audit->record('payments.connected', 'payment_account', 'payfast', ['mode' => $data['mode']]);

        return back()->with('status', 'PayFast is connected'.($data['mode'] === 'test' ? ' in test mode — no real money moves.' : '.'));
    }

    public function connectYoco(Request $request): RedirectResponse
    {
        $key = trim((string) $request->validate(['secret_key' => ['required', 'regex:/^sk_(test|live)_[A-Za-z0-9]+$/']],
            ['secret_key.regex' => 'Paste your Yoco secret key — it starts with sk_test_ or sk_live_ (Yoco portal → Sales → Payment gateway).'])['secret_key']);
        $mode = str_starts_with($key, 'sk_live_') ? 'live' : 'test';
        $account = new PaymentAccount(['provider' => 'yoco', 'mode' => $mode, ($mode === 'live' ? 'live_credentials' : 'test_credentials') => ['secret_key' => $key]]);
        // Registering our notification address doubles as the connection test: a wrong key is refused here.
        $hook = Yoco::register($account, 'https://'.$this->context->current()->handle().'.'.config('wurk.domains.root').'/payments/notify/yoco');
        if ($hook === null) {
            throw ValidationException::withMessages(['secret_key' => 'Yoco didn’t accept that key. Check it and try again.']);
        }
        PaymentAccount::query()->updateOrCreate(['provider' => 'yoco'], [
            'mode' => $mode, 'active' => true, ($mode === 'live' ? 'live_credentials' : 'test_credentials') => ['secret_key' => $key], 'webhook_id' => $hook[0], 'webhook_secret' => $hook[1],
            'label' => 'Key ••••'.substr($key, -4),
        ]);
        $this->audit->record('payments.connected', 'payment_account', 'yoco', ['mode' => $mode]);

        return back()->with('status', 'Yoco is connected'.($mode === 'test' ? ' in test mode — no real money moves.' : '.'));
    }

    public function disconnect(string $provider): RedirectResponse
    {
        $account = PaymentAccount::query()->where('provider', $provider)->firstOrFail();
        if ($provider === 'yoco' && $account->webhook_id !== null) {
            try { // best effort: stop Yoco calling us
                Http::timeout(10)->withToken((string) ($account->credentials['secret_key'] ?? ''))->delete(Yoco::API.'/webhooks/'.rawurlencode($account->webhook_id));
            } catch (Throwable) {
            }
        }
        $account->delete();
        $this->audit->record('payments.disconnected', 'payment_account', $provider, []);

        return back()->with('status', ($provider === 'yoco' ? 'Yoco' : 'PayFast').' is disconnected. Customers can’t pay with it any more.');
    }
}
