<?php

declare(strict_types=1);

namespace Wurk\Ai\Support;

use Wurk\Ai\Models\AiRequest;
use Wurk\Credits\Contracts\RateCard;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Plans\Contracts\CreditCostEstimates;

/**
 * Cost to WURK of one credit, per channel: message cost ÷ credits per message; AI
 * actions use the real average cost of the last 30 days (or a token estimate before
 * there is usage) × exchange rate × (1 + buffer) ÷ credits per action.
 */
final readonly class CostEstimates implements CreditCostEstimates
{
    /** Typical tokens per action before real usage exists. */
    private const ESTIMATE_TOKENS = ['generate_site' => [6000, 6000], 'rewrite' => [1500, 300]];

    public function __construct(private AiSettings $settings, private RateCard $rates, private ProviderCredentials $providers) {}

    public function perCredit(string $country = 'ZA'): array
    {
        $out = [];
        foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'Email'] as $channel => $label) {
            $cost = $this->providers->active($channel)['settings']['cost_zar'] ?? null;
            $credits = ($this->rates->rate($country, $channel) ?? 0) / 1000;
            $out[$channel] = ['label' => $label, 'zar_per_credit' => is_numeric($cost) && $credits > 0 ? (float) $cost / $credits : null];
        }

        $s = $this->settings->all();
        $zarPerUsd = (float) $s['fx_zar_per_usd'] * (1 + (float) $s['fx_buffer_pct'] / 100);
        foreach (AiActions::ACTIONS as $action => $meta) {
            $credits = ($this->rates->rate($country, 'ai.'.$action) ?? 0) / 1000;
            $avgMicro = AiRequest::query()->where('task', $meta['task'])->where('status', 'succeeded')->where('created_at', '>=', now()->subDays(30))->avg('cost_usd_micro');
            if ($avgMicro === null) {
                $step = $this->settings->chain($meta['task'])[0];
                [$in, $outTokens] = self::ESTIMATE_TOKENS[$action];
                $avgMicro = $this->settings->costMicro($step['provider'], $step['model'], $in, $outTokens);
            }
            $out['ai.'.$action] = ['label' => 'AI: '.$meta['label'], 'zar_per_credit' => $credits > 0 ? ((float) $avgMicro / 1_000_000) * $zarPerUsd / $credits : null];
        }

        return $out;
    }

    public function assumptions(): array
    {
        $s = $this->settings->all();

        return ['fx_zar_per_usd' => (float) $s['fx_zar_per_usd'], 'fx_buffer_pct' => (float) $s['fx_buffer_pct'], 'target_margin_pct' => (float) $s['target_margin_pct']];
    }
}
