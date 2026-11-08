<?php

declare(strict_types=1);

namespace Wurk\Agents\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;
use Wurk\Agents\Models\Agent;
use Wurk\Audit\Contracts\AuditLog;

/**
 * Which agent brought a business (Sprint 14.2). The agent's link (wurk.co.za/a/CODE) remembers the code in a cookie for
 * wurk.co.za and its sub-addresses (it holds only the public code); a business created during signup (app.) is
 * attributed to that agent. First link wins. Staff can correct it (logged).
 */
final readonly class AgentAttribution
{
    public const COOKIE = 'wurk_agent';

    public const DAYS = 90;

    public function __construct(private AuditLog $audit) {}

    public function activeByCode(string $code): ?Agent
    {
        return Agent::query()->where('code', mb_strtoupper(trim($code)))->where('status', 'active')->first();
    }

    public function cookie(Agent $agent, Request $request): Cookie
    {
        return \Illuminate\Support\Facades\Cookie::make(self::COOKIE, $agent->code, self::DAYS * 24 * 60, '/', '.'.config('wurk.domains.root'), $request->isSecure(), true, false, 'lax');
    }

    /** At signup: credit the agent whose link the visitor followed (only on the signup address; never for staff-created businesses). */
    public function fromSignup(string $tenantId, Request $request): void
    {
        if (strtolower($request->getHost()) !== strtolower((string) config('wurk.domains.app')) || ! $request->is('signup*')) {
            return;
        }
        $code = $request->cookie(self::COOKIE);
        $agent = is_string($code) ? $this->activeByCode($code) : null;
        if ($agent === null || DB::table('agent_businesses')->where('tenant_id', $tenantId)->exists()) {
            return;
        }
        DB::table('agent_businesses')->insert(['tenant_id' => $tenantId, 'agent_id' => $agent->id, 'source' => 'link', 'attributed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('agents.attributed', 'tenant', $tenantId, ['agent' => $agent->id, 'source' => 'link']);
    }

    /** A staff correction (null = no agent). */
    public function set(string $tenantId, ?string $agentId): void
    {
        $before = DB::table('agent_businesses')->where('tenant_id', $tenantId)->value('agent_id');
        if ($agentId === null) {
            DB::table('agent_businesses')->where('tenant_id', $tenantId)->delete();
        } else {
            DB::table('agent_businesses')->updateOrInsert(['tenant_id' => $tenantId], ['agent_id' => $agentId, 'source' => 'staff', 'attributed_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->audit->record('agents.attribution_changed', 'tenant', $tenantId, ['from' => $before, 'to' => $agentId]);
    }

    /** @return object{agent_id: string, source: string, attributed_at: string}|null */
    public function of(string $tenantId): ?object
    {
        /** @var object{agent_id: string, source: string, attributed_at: string}|null */
        return DB::table('agent_businesses')->where('tenant_id', $tenantId)->first();
    }
}
