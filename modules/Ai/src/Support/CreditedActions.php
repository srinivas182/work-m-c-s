<?php

declare(strict_types=1);

namespace Wurk\Ai\Support;

use Throwable;
use Wurk\Ai\Contracts\AiActionRefused;
use Wurk\Ai\Contracts\AiActions;
use Wurk\Ai\Models\AiRequest;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Every paid AI action asks first (spec §3, 6.3): the quote shows the exact credits,
 * the balance before and after, and — if it can't run — why and what to do. Perform
 * charges exactly the quoted price once (idempotent key), runs the work and refunds
 * automatically if it fails. The first website generation is free.
 */
final readonly class CreditedActions implements AiActions
{
    public function __construct(private TenantContext $context, private CreditWallet $wallet, private Entitlements $entitlements) {}

    /** @return array{action: string, label: string, credits: float, free: bool, balance: float, after: float, allowed: bool, reason: string|null, can_buy: bool} */
    public function quote(string $action): array
    {
        $tenant = $this->context->id();
        $free = $action === 'generate_site'
            && ! AiRequest::query()->where('tenant_id', $tenant)->where('task', 'site_generation')->where('status', 'succeeded')->exists();
        $costMilli = $free ? 0 : $this->wallet->rateFor('ai.'.$action);
        $balanceMilli = (int) $this->wallet->balance()['balance_milli'];
        $included = $this->entitlements->entitled($tenant, 'ai-assistant');
        // The first website is free on every package (PRD: AI site generation at onboarding);
        // everything after that needs a package that includes WURK AI.
        $spendable = $free ? null : $this->wallet->spendable($this->actor());
        $reason = ! $included && ! $free ? 'not_included'
            : ($balanceMilli < $costMilli ? 'insufficient' : ($spendable !== null && $spendable < $costMilli ? 'limit' : null));

        return [
            'action' => $action, 'label' => AiActions::ACTIONS[$action]['label'] ?? $action,
            'credits' => $costMilli / 1000, 'free' => $free, 'balance' => $balanceMilli / 1000, 'after' => ($balanceMilli - $costMilli) / 1000,
            'allowed' => $reason === null, 'reason' => $reason,
            'can_buy' => (bool) ($this->entitlements->limits($tenant)['credit_packs_allowed'] ?? true),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function perform(string $action, string $key, callable $work): mixed
    {
        $quote = $this->quote($action);
        if (! $quote['allowed']) {
            throw new AiActionRefused((string) $quote['reason'], match ($quote['reason']) {
                'not_included' => 'WURK AI isn’t included in your package.',
                'limit' => 'You’ve reached your monthly spending limit.',
                default => "You need {$quote['credits']} credits and have {$quote['balance']}.",
            });
        }
        $chargeKey = 'ai:'.$action.':'.$key;
        if (! $quote['free']) {
            $this->wallet->charge('ai.'.$action, $chargeKey, (string) AiActions::ACTIONS[$action]['label'], $this->actor());
        }
        try {
            return $work();
        } catch (Throwable $e) {
            if (! $quote['free']) {
                $this->wallet->refund($chargeKey, 'Refund: '.AiActions::ACTIONS[$action]['label'].' didn’t complete');
            }
            throw $e;
        }
    }

    /** Who is spending (for per-staff limits); null outside a signed-in request. */
    private function actor(): ?string
    {
        $id = auth()->id();

        return $id === null ? null : (string) $id;
    }
}
