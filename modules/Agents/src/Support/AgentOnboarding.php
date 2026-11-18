<?php

declare(strict_types=1);

namespace Wurk\Agents\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Agents\Models\Agent;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Contracts\CreditTopUps;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Identity\Contracts\Verification;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Tenancy\Contracts\TenantProvisioner;
use Wurk\Tenancy\Events\TenantClaimed;
use Wurk\Website\Contracts\SiteLaunches;

/**
 * An agent sets a business up on their phone, with the owner beside them (Sprint 23.4):
 *
 *   owner   — the owner's name, mobile and business; a one-time code goes to the owner's phone (WhatsApp, else SMS)
 *   verify  — the owner reads the code out; the number is now theirs, verified
 *   package — a package (pay as you go needs no card); the business is created, the owner is its owner, and the agent
 *             helps as a team member until hand-over. A demo the agent made earlier can become the business
 *   setup   — set up by talking or from a link (23.1, 23.2), in the business
 *   topup   — the first top-up from R20 online (Capitec Pay, SnapScan, card, mobile money) or cash to the agent
 *   handover — the owner gets their sign-in link and share kit on WhatsApp; the agent's access ends
 *
 * The business is credited to the agent (Sprint 14 commission rules). Nothing about the owner is kept here beyond
 * what the business needs.
 */
final readonly class AgentOnboarding
{
    public const STEPS = ['owner', 'verify', 'package', 'setup', 'topup', 'handover', 'done'];

    /** WhatsApp template on WURK's number: "{{1}} is ready on WURK, {{2}}. Sign in with your number: {{3}} Share your business: {{4}}" */
    public const TEMPLATE = 'wurk_agent_handover';

    private const PURPOSE = 'signup'; // the same code, wording and limits as signing up on one's own

    public function __construct(private Verification $verification, private Accounts $accounts, private PeopleDirectory $people, private TenantProvisioner $provisioner,
        private TenantDirectory $tenants, private TenantContext $context, private Catalog $catalog, private CreditTopUps $topUps, private Messenger $messenger,
        private SiteLaunches $launches, private AgentAttribution $attribution, private AgentDemos $demos, private AuditLog $audit) {}

    /** @return list<array{id: string, business: string, step: string, started: string, minutes: int|null, live: bool}> */
    public function recent(Agent $agent): array
    {
        return array_values(DB::table('agent_onboardings')->where('agent_id', $agent->id)->where('created_at', '>', now()->subDays(30))->orderByDesc('created_at')->limit(20)->get()
            ->map(fn ($o) => ['id' => (string) $o->id, 'business' => (string) (json_decode((string) $o->data, true)['business_name'] ?? ''), 'step' => (string) $o->step,
                'started' => (string) $o->created_at, 'minutes' => $o->handed_over_at === null ? null : self::minutes((string) $o->created_at, (string) $o->handed_over_at),
                'live' => $o->tenant_id !== null && $this->launches->launched((string) $o->tenant_id)])->all());
    }

    /** The agent's own onboarding (a row: id, agent_id, step, data (decoded), tenant_id, owner_identity_id, created_at, handed_over_at). */
    public function find(Agent $agent, string $id): \stdClass
    {
        $o = DB::table('agent_onboardings')->where('id', $id)->where('agent_id', $agent->id)->first() ?? abort(404);
        $o->data = (array) json_decode((string) $o->data, true);

        return $o;
    }

    /**
     * Step 1: who the owner is, and a code to their phone.
     *
     * @param  array{business_name: string, first_name: string, last_name: string, mobile: string, use_demo?: bool}  $owner
     */
    public function start(Agent $agent, array $owner, ?string $ip): string
    {
        if ($agent->status !== 'active') {
            throw ValidationException::withMessages(['mobile' => 'Your agent account is suspended.']);
        }
        if (str_contains($owner['mobile'], '@')) {
            throw ValidationException::withMessages(['mobile' => 'Use the owner’s mobile number.']);
        }
        $sent = $this->verification->send($owner['mobile'], self::PURPOSE, $ip, 'mobile');
        $demo = ($owner['use_demo'] ?? false) ? $this->demos->active($agent) : null;
        $id = (string) Str::ulid();
        DB::table('agent_onboardings')->insert(['id' => $id, 'agent_id' => $agent->id, 'step' => 'verify', 'data' => json_encode([
            'business_name' => trim($owner['business_name']), 'first_name' => trim($owner['first_name']), 'last_name' => trim($owner['last_name']),
            'mobile' => $sent['value'], 'masked' => $sent['masked'], 'demo_tenant_id' => $demo['tenant_id'] ?? null,
        ]), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('agents.onboarding_started', 'agent', $agent->id, ['onboarding' => $id], ['platform' => true]);

        return $id;
    }

    public function resend(\stdClass $o, ?string $ip): void
    {
        $this->assertStep($o, 'verify');
        $this->verification->send((string) $o->data['mobile'], self::PURPOSE, $ip, 'code');
    }

    /** Step 2: the code the owner received. */
    public function verify(\stdClass $o, string $code): void
    {
        $this->assertStep($o, 'verify');
        if (! $this->verification->confirm((string) $o->data['mobile'], $code, self::PURPOSE)) {
            throw ValidationException::withMessages(['code' => 'That code isn’t right or has expired. Check it, or send a new one.']);
        }
        $this->update($o, ['step' => 'package', 'verified_at' => now()]);
    }

    /** Step 3: the package; the business is created (or the agent's demo becomes it), owned by the owner, credited to the agent. */
    public function choosePackage(Agent $agent, \stdClass $o, string $packageId, string $period): CurrentTenant
    {
        $this->assertStep($o, 'package');
        if (! in_array($packageId, array_column($this->catalog->onSale(), 'id'), true)) {
            throw ValidationException::withMessages(['package_id' => 'Choose a package from the list.']);
        }
        $d = $o->data;
        $fingerprint = $this->verification->fingerprint((string) $d['mobile']);
        $trial = ! DB::table('trial_claims')->where('fingerprint', $fingerprint)->exists(); // one free trial per verified number (as at signup)

        return DB::transaction(function () use ($agent, $o, $d, $packageId, $period, $fingerprint, $trial): CurrentTenant {
            $demo = is_string($d['demo_tenant_id'] ?? null) && ($active = $this->demos->active($agent)) !== null && $active['tenant_id'] === $d['demo_tenant_id'];
            try {
                $tenant = $demo
                    ? $this->provisioner->claim((string) $d['demo_tenant_id'], (string) $d['business_name'], $this->handle((string) $d['business_name']))
                    : $this->provisioner->provision((string) $d['business_name'], $this->handle((string) $d['business_name']), 'ZA');
            } catch (\DomainException $e) {
                throw ValidationException::withMessages(['package_id' => $e->getMessage()]);
            }
            $owner = $this->accounts->forVerifiedContact((string) $d['mobile'], (string) $d['first_name'], (string) $d['last_name']);
            $this->accounts->makeOwner($owner, $tenant->tenantId(), trim($d['first_name'].' '.$d['last_name']));
            $subscription = $this->catalog->subscribe($tenant->tenantId(), $packageId, $period, allowTrial: $trial);
            if ($subscription['status'] === 'trial') {
                DB::table('trial_claims')->insert(['fingerprint' => $fingerprint, 'tenant_id' => $tenant->tenantId(), 'claimed_at' => now()]);
            }
            if (! $demo) {
                $this->attribution->set($tenant->tenantId(), $agent->id, 'agent');
                // The agent helps set it up as a team member until hand-over (a demo's agent is its owner already).
                $this->context->run($tenant, fn () => app(TeamDirectory::class)->add($agent->identity_id, $this->agentName($agent), 'owner'));
            }
            $this->update($o, ['step' => 'setup', 'tenant_id' => $tenant->tenantId(), 'owner_identity_id' => $owner,
                'data' => json_encode([...$d, 'package_id' => $packageId, 'period' => $period, 'claimed_demo' => $demo, 'handle' => $tenant->handle()])]);
            $this->audit->record('agents.onboarding_business', 'tenant', $tenant->tenantId(), ['agent' => $agent->id, 'onboarding' => $o->id, 'demo' => $demo], ['platform' => true]);

            return $tenant;
        });
    }

    /** Step 4 done (or skipped): on to the first top-up. */
    public function setupDone(\stdClass $o): void
    {
        $this->assertStep($o, 'setup');
        $this->update($o, ['step' => 'topup']);
    }

    /** Step 5, online: where to send the owner to pay (on the agent's phone or their own). */
    public function topUpOnline(\stdClass $o, int $rand, string $email, ?string $method, string $returnUrl): string
    {
        $this->assertStep($o, 'topup');
        $url = $this->topUps->checkout((string) $o->tenant_id, $rand, $email, (string) $o->data['mobile'], $returnUrl, $method);
        if ($url === null) {
            throw ValidationException::withMessages(['method' => 'Online payments aren’t available right now. Take cash, or let the owner top up later.']);
        }
        $this->update($o, ['data' => json_encode([...$o->data, 'top_up' => ['rand' => $rand, 'how' => 'online']])]);

        return $url;
    }

    /** Back from the checkout: paid, still waiting, or failed. */
    public function topUpReturned(\stdClass $o, string $reference): string
    {
        $this->assertStep($o, 'topup');
        $status = $this->topUps->status((string) $o->tenant_id, $reference);
        if ($status === 'succeeded') {
            $this->update($o, ['step' => 'handover', 'data' => json_encode([...$o->data, 'top_up' => [...(array) ($o->data['top_up'] ?? []), 'paid' => true]])]);
        }

        return $status;
    }

    /** Step 5, cash: the agent took it and gives a receipt number; it counts once WURK approves it. */
    public function topUpCash(Agent $agent, \stdClass $o, int $rand, string $receipt): void
    {
        $this->assertStep($o, 'topup');
        $this->topUps->recordCash((string) $o->tenant_id, $rand, trim($o->data['first_name'].' '.$o->data['last_name']), $receipt, $agent->identity_id);
        $this->update($o, ['step' => 'handover', 'data' => json_encode([...$o->data, 'top_up' => ['rand' => $rand, 'how' => 'cash', 'receipt' => $receipt]])]);
    }

    public function skipTopUp(\stdClass $o): void
    {
        $this->assertStep($o, 'topup');
        $this->update($o, ['step' => 'handover']);
    }

    /**
     * Step 6: the owner's sign-in link and share kit by WhatsApp (SMS when WhatsApp can't), and the agent's access ends.
     *
     * @return int minutes from start to hand-over
     */
    public function handOver(Agent $agent, \stdClass $o): int
    {
        $this->assertStep($o, 'handover');
        $tenant = $this->tenants->find((string) $o->tenant_id) ?? abort(404);
        $d = $o->data;
        $signIn = 'https://'.config('wurk.domains.app').'/login';
        $share = 'https://'.config('wurk.domains.app').'/'.$tenant->handle().'/website/share';
        $sent = $this->messenger->whatsappTemplate((string) $d['mobile'], self::TEMPLATE, [$tenant->displayName(), (string) $d['first_name'], $signIn, $share], 'agent_handover');
        if (! $sent->ok) {
            $this->messenger->sms((string) $d['mobile'], "WURK: {$tenant->displayName()} is ready, {$d['first_name']}. Sign in with your number: {$signIn} Share your business: {$share}", 'agent_handover');
        }
        if (($d['claimed_demo'] ?? false) === true) {
            event(new TenantClaimed($tenant->tenantId(), (string) $o->owner_identity_id)); // the demo is theirs: the agent's access ends, credited as a demo
        } else {
            $this->context->run($tenant, function () use ($agent): void {
                $team = app(TeamDirectory::class);
                foreach ($team->roster() as $m) {
                    if ($m['identity_id'] === $agent->identity_id && $m['active']) {
                        $team->deactivate($m['id']); // the agent's access ends; their work stays on record
                    }
                }
            });
        }
        $minutes = self::minutes((string) $o->created_at, (string) now());
        $this->update($o, ['step' => 'done', 'handed_over_at' => now()]);
        $this->audit->record('agents.onboarding_handed_over', 'tenant', $tenant->tenantId(), ['agent' => $agent->id, 'minutes' => $minutes, 'whatsapp' => $sent->ok], ['platform' => true]);

        return $minutes;
    }

    /** Whole minutes from start to hand-over, at least 1. */
    private static function minutes(string $from, string $to): int
    {
        return max(1, (int) ceil((strtotime($to) - strtotime($from)) / 60));
    }

    private function handle(string $business): string
    {
        $slug = trim(substr(Str::slug($business), 0, 28), '-') ?: 'business';
        $handle = $slug;
        for ($n = 2; $this->provisioner->handleProblem($handle) !== null; $n++) {
            $handle = $n < 20 ? $slug.'-'.$n : $slug.'-'.Str::lower(Str::random(4));
        }

        return $handle;
    }

    private function agentName(Agent $agent): string
    {
        try {
            return ($this->people->names([$agent->identity_id])[$agent->identity_id] ?? 'Agent').' (WURK agent)';
        } catch (Throwable) {
            return 'WURK agent';
        }
    }

    private function assertStep(\stdClass $o, string $step): void
    {
        if ($o->step !== $step) {
            throw ValidationException::withMessages(['step' => 'That step is done. Carry on from where you are.']);
        }
    }

    /** @param  array<string, mixed>  $values */
    private function update(\stdClass $o, array $values): void
    {
        DB::table('agent_onboardings')->where('id', $o->id)->update([...$values, 'updated_at' => now()]);
        foreach ($values as $k => $v) {
            $o->{$k} = $k === 'data' ? (array) json_decode((string) $v, true) : $v;
        }
    }
}
