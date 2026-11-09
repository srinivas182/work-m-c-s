<?php

declare(strict_types=1);

namespace Wurk\Agents\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Wurk\Agents\Models\Agent;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Identity\Contracts\Verification;

/**
 * Agents' banking details (Sprint 14.7): a change is confirmed with a code to the agent's phone (email if they have no
 * phone) before it's saved; held encrypted in the session meanwhile; the account number is encrypted at rest, shown
 * masked and never logged.
 */
final readonly class AgentBanking
{
    private const PENDING = 'agent.bank_pending';

    public const PURPOSE = 'agent_bank';

    public function __construct(private Verification $verification, private PeopleDirectory $people, private AuditLog $audit) {}

    /** @return array{holder: string, bank: string, branch_code: string, account_type: string, last4: string, changed_at: string}|null */
    public function current(Agent $agent): ?array
    {
        $r = DB::table('agent_bank_accounts')->where('agent_id', $agent->id)->first();

        return $r === null ? null : ['holder' => (string) $r->holder, 'bank' => (string) $r->bank, 'branch_code' => (string) $r->branch_code,
            'account_type' => (string) $r->account_type, 'last4' => (string) $r->account_last4, 'changed_at' => substr((string) $r->changed_at, 0, 10)];
    }

    /** @param array{holder: string, bank: string, branch_code: string, account_type: string, account: string} $details  @return string where the code went (masked) */
    public function request(Request $request, Agent $agent, array $details): string
    {
        $request->session()->put(self::PENDING, Crypt::encryptString((string) json_encode($details)));
        $sent = $this->verification->send($this->destination($agent), self::PURPOSE, $request->ip());

        return $sent['masked'];
    }

    public function hasPending(Request $request): bool
    {
        return $request->session()->has(self::PENDING);
    }

    /** @throws \DomainException wrong or expired code */
    public function confirm(Request $request, Agent $agent, string $code): void
    {
        $sealed = $request->session()->get(self::PENDING);
        if (! is_string($sealed) || ! $this->verification->confirm($this->destination($agent), trim($code), self::PURPOSE)) {
            throw new \DomainException('That code isn’t right, or it has expired. Ask for a new one.');
        }
        /** @var array{holder: string, bank: string, branch_code: string, account_type: string, account: string} $d */
        $d = json_decode(Crypt::decryptString($sealed), true);
        $account = preg_replace('/\D/', '', $d['account']) ?? '';
        DB::table('agent_bank_accounts')->updateOrInsert(['agent_id' => $agent->id], [
            'holder' => $d['holder'], 'bank' => $d['bank'], 'branch_code' => $d['branch_code'], 'account_type' => $d['account_type'],
            'account_encrypted' => Crypt::encryptString($account), 'account_last4' => substr($account, -4), 'changed_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        $request->session()->forget(self::PENDING);
        $this->audit->record('agents.bank_changed', 'agent', $agent->id, ['bank' => $d['bank'], 'last4' => substr($account, -4)]); // never the number
    }

    /** The full account number — only for building the payout batch (14.5). */
    public function accountNumber(string $agentId): ?string
    {
        $enc = DB::table('agent_bank_accounts')->where('agent_id', $agentId)->value('account_encrypted');

        return $enc === null ? null : Crypt::decryptString((string) $enc);
    }

    private function destination(Agent $agent): string
    {
        $c = $this->people->contacts([$agent->identity_id])[$agent->identity_id] ?? null;

        return (string) ($c['phone'] ?? $c['email'] ?? throw new \DomainException('We have no phone number or email for you. Contact WURK.'));
    }
}
