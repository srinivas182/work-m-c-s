<?php

declare(strict_types=1);

namespace Wurk\Onboarding\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Contracts\Verification;
use Wurk\Onboarding\Models\Signup;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantProvisioner;

/**
 * Self-service signup (PRD §5.2, Sprint 3 scope): details → verify → package →
 * web address → finish. Category, template and AI site steps join later.
 */
final class SignupController
{
    private const SESSION = 'signup.id';

    public function __construct(
        private readonly Verification $verification,
        private readonly Catalog $catalog,
        private readonly TenantProvisioner $tenants,
        private readonly AuditLog $audit,
    ) {}

    public function show(Request $request, Countries $countries): Response
    {
        $signup = $this->current($request);
        if ($signup?->completed_at !== null) {
            $request->session()->forget(self::SESSION);
            $signup = null;
        }

        $packages = $this->catalog->onSale();
        $data = $signup->data ?? [];

        return Inertia::render('Onboarding::Signup', [
            'step' => $signup->step ?? 'details',
            'steps' => Signup::STEPS,
            'data' => [
                'business_name' => $data['business_name'] ?? '',
                'first_name' => $data['first_name'] ?? '',
                'last_name' => $data['last_name'] ?? '',
                'contact' => $data['contact'] ?? '',
                'country' => $data['country'] ?? 'ZA',
                'package_id' => $data['package_id'] ?? $this->packageFromQuery($request, $packages),
                'period' => $data['period'] ?? ($request->query('period') === 'yearly' ? 'yearly' : 'monthly'),
                'handle' => $data['handle'] ?? '',
            ],
            'maskedContact' => $data['masked'] ?? null,
            'packages' => $packages,
            'countries' => array_map(fn (array $c) => ['code' => $c['code'], 'name' => $c['name'], 'vat' => $c['vat_bp'] / 100], $countries->enabled()),
            'trialAvailable' => $signup === null || ! $this->trialClaimed($signup->fingerprint),
            'rootDomain' => config('wurk.domains.root'),
        ]);
    }

    public function details(Request $request, Countries $countries): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'contact' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2'],
        ]);

        if (! in_array($data['country'], array_column($countries->enabled(), 'code'), true)) {
            throw ValidationException::withMessages(['country' => 'WURK isn’t available in that country yet.']);
        }
        if (str_contains($data['contact'], '@') && in_array(strtolower((string) substr(strrchr($data['contact'], '@') ?: '', 1)), (array) config('onboarding.disposable-domains', []), true)) {
            throw ValidationException::withMessages(['contact' => 'Use an email address you’ll keep — temporary mailboxes can’t be used.']);
        }

        $sent = $this->verification->send($data['contact'], 'signup', $request->ip());
        $fingerprint = $this->verification->fingerprint($data['contact']);

        // Same person, unfinished signup within 7 days: carry on with it.
        $signup = Signup::query()->where('fingerprint', $fingerprint)->whereNull('completed_at')->where('created_at', '>', now()->subDays(7))->latest()->first()
            ?? $this->current($request);
        $merged = [...($signup->data ?? []), ...$data, 'contact' => $sent['value'], 'masked' => $sent['masked']];

        if ($signup === null || $signup->completed_at !== null) {
            $signup = Signup::query()->create(['fingerprint' => $fingerprint, 'step' => 'verify', 'data' => $merged, 'ip' => $request->ip()]);
        } else {
            $signup->update(['fingerprint' => $fingerprint, 'step' => 'verify', 'data' => $merged, 'verified_at' => null]);
        }

        $request->session()->put(self::SESSION, $signup->id);

        return back();
    }

    public function resend(Request $request): RedirectResponse
    {
        $signup = $this->require($request, 'verify');
        $this->verification->send((string) $signup->data['contact'], 'signup', $request->ip(), 'code');

        return back()->with('status', 'We sent a new code.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $signup = $this->require($request, 'verify');
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        if (! $this->verification->confirm((string) $signup->data['contact'], $code, 'signup')) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right or has expired. Check it, or ask for a new one.']);
        }

        // Resume: a package or address chosen earlier is kept.
        $next = isset($signup->data['handle']) ? 'finish' : (isset($signup->data['package_id']) ? 'address' : 'package');
        $signup->update(['verified_at' => now(), 'step' => $next]);

        return back();
    }

    public function package(Request $request): RedirectResponse
    {
        $signup = $this->require($request, 'package', 'address', 'finish');
        $data = $request->validate([
            'package_id' => ['required', 'string', 'in:'.implode(',', array_column($this->catalog->onSale(), 'id'))],
            'period' => ['required', 'in:monthly,yearly'],
        ]);
        $signup->update(['step' => isset($signup->data['handle']) ? 'finish' : 'address', 'data' => [...$signup->data, ...$data]]);

        return back();
    }

    public function checkHandle(Request $request): JsonResponse
    {
        $problem = $this->tenants->handleProblem((string) $request->query('handle', ''));

        return new JsonResponse(['available' => $problem === null, 'problem' => $problem]);
    }

    public function address(Request $request): RedirectResponse
    {
        $signup = $this->require($request, 'address', 'finish');
        $handle = strtolower(trim((string) $request->validate(['handle' => ['required', 'string', 'max:40']])['handle']));

        if (($problem = $this->tenants->handleProblem($handle)) !== null) {
            throw ValidationException::withMessages(['handle' => $problem]);
        }
        $signup->update(['step' => 'finish', 'data' => [...$signup->data, 'handle' => $handle]]);

        return back();
    }

    public function back(Request $request, string $step): RedirectResponse
    {
        $signup = $this->require($request, 'package', 'address', 'finish');
        if (in_array($step, ['package', 'address'], true)) {
            $signup->update(['step' => $step]);
        }

        return back();
    }

    public function complete(Request $request, Accounts $accounts): RedirectResponse
    {
        $signup = $this->require($request, 'finish');
        $d = $signup->data;

        // One free trial per verified email or number; otherwise the business starts unpaid
        // and the owner pays for the first period straight away (Billing).
        $trial = ! $this->trialClaimed($signup->fingerprint);

        $result = DB::transaction(function () use ($signup, $d, $accounts, $trial): array {
            $locked = Signup::query()->whereKey($signup->id)->lockForUpdate()->firstOrFail();
            if ($locked->completed_at !== null) {
                return ['handle' => (string) $locked->data['handle'], 'identity' => (string) $locked->data['identity_id']];
            }

            $tenant = $this->tenants->provision((string) $d['business_name'], (string) $d['handle'], (string) $d['country']);
            $identityId = $accounts->forVerifiedContact((string) $d['contact'], (string) $d['first_name'], (string) $d['last_name']);
            $accounts->makeOwner($identityId, $tenant->tenantId(), trim($d['first_name'].' '.$d['last_name']));
            $subscription = $this->catalog->subscribe($tenant->tenantId(), (string) $d['package_id'], (string) $d['period'], allowTrial: $trial);

            if ($subscription['status'] === 'trial') {
                DB::table('trial_claims')->insert(['fingerprint' => $locked->fingerprint, 'tenant_id' => $tenant->tenantId(), 'claimed_at' => now()]);
            }
            $locked->update(['completed_at' => now(), 'tenant_id' => $tenant->tenantId(), 'data' => [...$locked->data, 'identity_id' => $identityId]]);
            $this->audit->record('signup.completed', 'tenant', $tenant->tenantId(), ['package_id' => $d['package_id'], 'period' => $d['period'], 'status' => $subscription['status']], ['platform' => true, 'actor_id' => $identityId]);

            return ['handle' => $tenant->handle(), 'identity' => $identityId];
        });

        $request->session()->forget(self::SESSION);

        $onTrial = $trial && ($this->catalog->subscriptionOf((string) $signup->fresh()?->tenant_id)['status'] ?? '') === 'trial';

        return $accounts->signIn($request, $result['identity'], $onTrial ? '/'.$result['handle'].'?welcome=1' : '/'.$result['handle'].'/settings/billing');
    }

    private function current(Request $request): ?Signup
    {
        $id = $request->session()->get(self::SESSION);

        return is_string($id) ? Signup::query()->find($id) : null;
    }

    private function require(Request $request, string ...$steps): Signup
    {
        $signup = $this->current($request);
        $verifiedOk = $signup !== null && ($signup->verified_at !== null || $steps === ['verify']);
        if ($signup === null || ! $signup->isOpen() || ! in_array($signup->step, $steps, true) || ! $verifiedOk) {
            throw ValidationException::withMessages(['signup' => 'Your signup expired. Please start again.']);
        }

        return $signup;
    }

    private function trialClaimed(string $fingerprint): bool
    {
        return DB::table('trial_claims')->where('fingerprint', $fingerprint)->exists();
    }

    /** @param  list<array{id: string, key: string}>  $packages */
    private function packageFromQuery(Request $request, array $packages): string
    {
        $key = (string) $request->query('package', '');
        foreach ($packages as $p) {
            if ($p['key'] === $key) {
                return $p['id'];
            }
        }

        return $packages[0]['id'] ?? '';
    }
}
