<?php

declare(strict_types=1);

namespace Wurk\Agents\Support;

use Wurk\Agents\Models\Agent;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\Accounts;

/** The sponsor hierarchy (Sprint 14.1): creating agents, moving them (never into a loop), suspending, commission duration. */
final readonly class AgentRoster
{
    /** No look-alike characters (0/O, 1/I/L). */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(private Accounts $accounts, private AuditLog $audit) {}

    /** @throws \DomainException a message for WURK staff */
    public function create(string $contact, string $first, string $last, ?string $sponsorId, ?int $months): Agent
    {
        $identity = $this->accounts->forVerifiedContact($contact, $first, $last);
        if (Agent::query()->where('identity_id', $identity)->exists()) {
            throw new \DomainException('This person is already an agent.');
        }
        if ($sponsorId !== null && ! Agent::query()->whereKey($sponsorId)->exists()) {
            throw new \DomainException('That sponsor isn’t an agent.');
        }
        do {
            $code = '';
            for ($i = 0; $i < 7; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (Agent::query()->where('code', $code)->exists());
        $agent = Agent::query()->create(['identity_id' => $identity, 'code' => $code, 'sponsor_id' => $sponsorId, 'status' => 'active', 'commission_months' => $months]);
        $this->audit->record('agents.created', 'agent', $agent->id, ['sponsor' => $sponsorId, 'months' => $months]);

        return $agent;
    }

    /** @throws \DomainException a loop, or an unknown sponsor */
    public function move(Agent $agent, ?string $sponsorId): void
    {
        if ($sponsorId !== null) {
            $sponsor = Agent::query()->find($sponsorId) ?? throw new \DomainException('That sponsor isn’t an agent.');
            for ($a = $sponsor, $hops = 0; $a !== null && $hops < 100; $hops++) { // walk up from the new sponsor
                if ($a->id === $agent->id) {
                    throw new \DomainException('That would put the agent under their own team.');
                }
                $a = $a->sponsor_id === null ? null : Agent::query()->find($a->sponsor_id);
            }
        }
        $from = $agent->sponsor_id;
        $agent->update(['sponsor_id' => $sponsorId]);
        $this->audit->record('agents.moved', 'agent', $agent->id, ['from' => $from, 'to' => $sponsorId]);
    }

    public function update(Agent $agent, string $status, ?int $months): void
    {
        $before = ['status' => $agent->status, 'months' => $agent->commission_months];
        $agent->update(['status' => $status, 'commission_months' => $months]);
        $this->audit->record('agents.updated', 'agent', $agent->id, ['before' => $before, 'after' => ['status' => $status, 'months' => $months]]);
    }

    /** @return list<string> the agent's whole team below them (any depth) */
    public function downline(string $agentId): array
    {
        $all = [];
        for ($level = [$agentId], $depth = 0; $level !== [] && $depth < 50; $depth++) {
            $level = array_values(array_map('strval', Agent::query()->whereIn('sponsor_id', $level)->pluck('id')->all()));
            $all = [...$all, ...$level];
        }

        return $all;
    }
}
