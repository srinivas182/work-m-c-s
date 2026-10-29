<?php

declare(strict_types=1);

namespace Wurk\Shipping\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Shipping\Gateways\BobGo;
use Wurk\Shipping\Gateways\Pargo;
use Wurk\Shipping\Models\ShippingAccount;
use Wurk\Tenancy\Contracts\BranchDirectory;

/** Settings → Shipping: the business's (or a branch's) own Bob Go and Pargo accounts. */
final class ShippingSettingsController
{
    private const NAMES = ['bobgo' => 'Bob Go (courier delivery)', 'pargo' => 'Pargo (pickup points)'];

    private const FIELDS = ['bobgo' => ['api_key', 'webhook_secret'], 'pargo' => ['username', 'password', 'map_token']];

    public function __construct(private BranchDirectory $branches, private AuditLog $audit) {}

    public function index(): Response
    {
        $all = ShippingAccount::query()->get();
        $open = $this->branches->open();
        $summary = fn (?ShippingAccount $a) => $a === null ? null : ['active' => $a->active, 'mode' => $a->mode, 'label' => $a->label, 'has_test' => $a->hasKeys('test'), 'has_live' => $a->hasKeys('live'), 'flat_rate_cents' => $a->flat_rate_cents];

        return Inertia::render('Shipping::Settings', [
            'providers' => array_map(fn (string $p) => [
                'provider' => $p, 'name' => self::NAMES[$p],
                'account' => $summary($all->first(fn (ShippingAccount $a) => $a->provider === $p && $a->branch_id === null)),
                'branches' => count($open) > 1 ? (object) collect($open)->mapWithKeys(fn (array $b) => [$b['id'] => $summary($all->first(fn (ShippingAccount $a) => $a->provider === $p && $a->branch_id === $b['id']))])->all() : (object) [],
            ], array_keys(self::NAMES)),
            'branches' => count($open) > 1 ? array_map(fn (array $b) => ['id' => $b['id'], 'name' => $b['name']], $open) : [],
        ]);
    }

    public function keys(Request $request, string $provider, string $mode): RedirectResponse
    {
        abort_unless(isset(self::FIELDS[$provider]) && in_array($mode, ['test', 'live'], true), 404);
        $rules = $provider === 'bobgo'
            ? ['api_key' => ['required', 'string', 'max:300'], 'webhook_secret' => ['nullable', 'string', 'max:300']]
            : ['username' => ['required', 'string', 'max:200'], 'password' => ['required', 'string', 'max:200'], 'map_token' => ['nullable', 'string', 'max:300']];
        $keys = array_filter($request->validate($rules), fn ($v) => $v !== null && $v !== '');
        $branch = $this->branch($request);
        $account = $this->find($provider, $branch) ?? new ShippingAccount(['provider' => $provider, 'branch_id' => $branch, 'mode' => $mode, 'active' => false]);
        $probe = new ShippingAccount(['provider' => $provider, 'mode' => $mode, "{$mode}_credentials" => $keys]);
        if (! ($provider === 'bobgo' ? BobGo::check($probe) : Pargo::check($probe))) {
            throw ValidationException::withMessages([self::FIELDS[$provider][0] => $provider === 'bobgo' ? 'Bob Go didn’t accept that key.' : 'Pargo didn’t accept that username and password.']);
        }
        $account->fill(["{$mode}_credentials" => $keys, 'label' => $provider === 'bobgo' ? 'Key …'.substr((string) $keys['api_key'], -4) : (string) $keys['username']])->save();
        $this->audit->record('shipping.keys_saved', 'shipping_account', $account->id, ['provider' => $provider, 'mode' => $mode, 'branch' => $branch]);

        return back()->with('status', self::NAMES[$provider].": {$mode} details saved.");
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(isset(self::FIELDS[$provider]), 404);
        $data = $request->validate(['active' => ['required', 'boolean'], 'mode' => ['required', 'in:test,live'], 'flat_rate_cents' => ['nullable', 'integer', 'min:0', 'max:1000000']]);
        $account = $this->find($provider, $this->branch($request)) ?? abort(404);
        if ($data['active'] && ! $account->hasKeys($data['mode'])) {
            throw ValidationException::withMessages(['mode' => "Add the {$data['mode']} details first."]);
        }
        if ($provider === 'pargo' && $data['active'] && ($data['flat_rate_cents'] ?? $account->flat_rate_cents) === null) {
            throw ValidationException::withMessages(['flat_rate_cents' => 'Set what customers pay for a pickup point.']);
        }
        $account->update(['active' => (bool) $data['active'], 'mode' => $data['mode'], 'flat_rate_cents' => $data['flat_rate_cents'] ?? $account->flat_rate_cents]);
        $this->audit->record('shipping.updated', 'shipping_account', $account->id, ['active' => $account->active, 'mode' => $account->mode]);

        return back()->with('status', $account->active ? self::NAMES[$provider].' is on'.($account->mode === 'test' ? ' in test mode.' : '.') : self::NAMES[$provider].' is off.');
    }

    public function disconnect(Request $request, string $provider): RedirectResponse
    {
        ($this->find($provider, $this->branch($request)) ?? abort(404))->delete();
        $this->audit->record('shipping.disconnected', 'shipping_account', $provider, []);

        return back()->with('status', self::NAMES[$provider].' disconnected.');
    }

    private function branch(Request $request): ?string
    {
        $b = $request->query('branch');
        if ($b === null || $b === '') {
            return null;
        }
        abort_unless(in_array($b, array_column($this->branches->open(), 'id'), true), 404);

        return (string) $b;
    }

    private function find(string $provider, ?string $branch): ?ShippingAccount
    {
        return ShippingAccount::query()->where('provider', $provider)->when($branch === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branch))->first();
    }
}
