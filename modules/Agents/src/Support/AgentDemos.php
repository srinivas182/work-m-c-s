<?php

declare(strict_types=1);

namespace Wurk\Agents\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Wurk\Agents\Mail\AgentDemoExpiringMail;
use Wurk\Agents\Models\Agent;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Tenancy\Contracts\TenantProvisioner;
use Wurk\Website\Contracts\SiteTemplates;

/**
 * Agents' demo sites (Sprint 14.8a): a real business marked as a demo, built from an industry template and published at
 * once; the agent is its owner while it's a demo. One active per agent; 14 days; reminders on days 10 and 13; archived
 * on day 14 (site offline, address freed — permanently purged in Sprint 18).
 */
final readonly class AgentDemos
{
    public const DAYS = 14;

    public function __construct(private TenantProvisioner $provisioner, private TenantDirectory $tenants, private TenantContext $context,
        private SiteTemplates $templates, private Accounts $accounts, private PeopleDirectory $people, private AuditLog $audit) {}

    /** @return array{id: string, tenant_id: string, business: string, handle: string, template: string, expires_at: string, days_left: int}|null */
    public function active(Agent $agent): ?array
    {
        $d = DB::table('agent_demos')->where('agent_id', $agent->id)->whereNull('archived_at')->whereNull('claimed_at')->first();
        $t = $d === null ? null : $this->tenants->find((string) $d->tenant_id);
        if ($d === null || $t === null) {
            return null;
        }

        return ['id' => (string) $d->id, 'tenant_id' => (string) $d->tenant_id, 'business' => $t->displayName(), 'handle' => $t->handle(), 'template' => (string) $d->template,
            'expires_at' => substr((string) $d->expires_at, 0, 10), 'days_left' => max(0, (int) ceil(now()->diffInHours($d->expires_at, false) / 24))];
    }

    /** @throws \DomainException a plain reason */
    public function create(Agent $agent, string $business, string $categoryKey, string $templateKey): string
    {
        if ($agent->status !== 'active') {
            throw new \DomainException('Your agent account is suspended.');
        }
        if ($this->active($agent) !== null) {
            throw new \DomainException('You already have a demo. Delete it to make another.');
        }
        if (! $this->templates->offers($categoryKey, $templateKey)) {
            throw new \DomainException('Choose a template from the list.');
        }
        $slug = trim(substr(Str::slug($business), 0, 24), '-') ?: 'business';
        do {
            $handle = $slug.'-demo-'.Str::lower(Str::random(4));
        } while ($this->provisioner->handleProblem($handle) !== null);
        $tenant = $this->provisioner->provision($business, $handle, 'ZA');
        $this->provisioner->markDemo($tenant->tenantId(), true);
        $this->accounts->makeOwner($agent->identity_id, $tenant->tenantId(), ($this->people->names([$agent->identity_id])[$agent->identity_id] ?? 'Agent'));
        $this->context->run($tenant, function () use ($templateKey): void {
            $this->templates->apply($templateKey);
            $this->templates->publishAll('Demo');
        });
        $id = (string) Str::ulid();
        DB::table('agent_demos')->insert(['id' => $id, 'agent_id' => $agent->id, 'tenant_id' => $tenant->tenantId(), 'template' => $templateKey,
            'expires_at' => now()->addDays(self::DAYS), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('agents.demo_created', 'tenant', $tenant->tenantId(), ['agent' => $agent->id, 'template' => $templateKey]);

        return $tenant->tenantId();
    }

    public function delete(Agent $agent): void
    {
        $demo = $this->active($agent) ?? throw new \DomainException('You have no demo to delete.');
        $this->archive($demo['id'], 'deleted');
    }

    /**
     * Daily: reminders on days 10 and 13, archive on day 14.
     *
     * @return array{reminded: int, archived: int}
     */
    public function sweep(): array
    {
        $out = ['reminded' => 0, 'archived' => 0];
        foreach (DB::table('agent_demos')->whereNull('archived_at')->whereNull('claimed_at')->get() as $d) {
            $left = now()->diffInHours($d->expires_at, false) / 24;
            if ($left <= 0) {
                $this->archive((string) $d->id, 'expired');
                $out['archived']++;
            } elseif ($left <= 1 && $d->reminded_13_at === null) {
                $this->remind($d, 1, 'reminded_13_at');
                $out['reminded']++;
            } elseif ($left <= 4 && $d->reminded_10_at === null) {
                $this->remind($d, 4, 'reminded_10_at');
                $out['reminded']++;
            }
        }

        return $out;
    }

    private function remind(\stdClass $demo, int $days, string $column): void
    {
        DB::table('agent_demos')->where('id', $demo->id)->update([$column => now(), 'updated_at' => now()]);
        $agent = Agent::query()->where('id', (string) $demo->agent_id)->first();
        $person = $agent === null ? null : ($this->people->contacts([$agent->identity_id])[$agent->identity_id] ?? null);
        $tenant = $this->tenants->find((string) $demo->tenant_id);
        if ($tenant !== null && ($person['email'] ?? null) !== null) {
            Mail::to((string) $person['email'])->send(new AgentDemoExpiringMail((string) $person['name'], $tenant->displayName(), $days,
                'http://'.$tenant->handle().'.'.config('wurk.domains.root')));
        }
    }

    private function archive(string $demoId, string $why): void
    {
        $demo = DB::table('agent_demos')->where('id', $demoId)->first() ?? throw new \DomainException('No such demo.');
        $this->provisioner->archive((string) $demo->tenant_id);
        DB::table('agent_demos')->where('id', $demoId)->update(['archived_at' => now(), 'updated_at' => now()]);
        $this->audit->record('agents.demo_archived', 'tenant', (string) $demo->tenant_id, ['agent' => $demo->agent_id, 'why' => $why]);
    }
}
