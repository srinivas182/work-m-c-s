<?php

declare(strict_types=1);

namespace Wurk\Ai\Support;

use Wurk\Security\Contracts\PlatformSettings;

/**
 * WURK staff choose the AI behind "WURK AI": model per task (pinned versions, never
 * "latest"), fallback order, model prices, exchange rate and margin. Defaults are
 * editable placeholders — the exchange rate in particular must be kept current.
 */
final readonly class AiSettings
{
    public const PROVIDERS = ['anthropic' => 'Anthropic (Claude)', 'google' => 'Google (Gemini)', 'openai' => 'OpenAI', 'simulated' => 'Simulated (development only)'];

    public const TASKS = ['site_generation' => 'Write a website', 'rewrite' => 'Rewrite a section'];

    public const DEFAULTS = [
        'tasks' => [
            'site_generation' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
            'rewrite' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'],
        ],
        'fallback' => [['provider' => 'google', 'model' => 'gemini-3.8-flash'], ['provider' => 'openai', 'model' => 'gpt-6-sol']],
        // US dollars per million tokens (input, output) — from each provider's pricing page; keep current.
        'prices' => [
            'anthropic:claude-sonnet-5-5' => [2.0, 10.0],
            'anthropic:claude-haiku-5-5' => [0.10, 0.50],
            'google:gemini-3.8-flash' => [0.75, 3.75],
            'openai:gpt-6-sol' => [2.0, 10.0],
            'simulated:simulated' => [0.0, 0.0],
        ],
        'fx_zar_per_usd' => 18.0,   // placeholder until set by WURK
        'fx_buffer_pct' => 5,       // placeholder until confirmed
        'target_margin_pct' => 40,  // placeholder until confirmed
        'caps' => ['provider_monthly_usd' => [], 'tenant_daily_requests' => 50],
    ];

    public function __construct(private PlatformSettings $settings) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        $saved = (array) $this->settings->get('ai', []);

        return array_replace_recursive(self::DEFAULTS, $saved);
    }

    /** @param  array<string, mixed>  $settings */
    public function save(array $settings, ?string $by): void
    {
        $this->settings->set('ai', $settings, $by);
    }

    /** @return list<array{provider: string, model: string}> the task's model followed by the fallback order */
    public function chain(string $task): array
    {
        $all = $this->all();
        $first = $all['tasks'][$task] ?? $all['tasks']['site_generation'];
        $chain = [$first];
        foreach ((array) $all['fallback'] as $f) {
            if ($f !== $first) {
                $chain[] = $f;
            }
        }

        return $chain;
    }

    /** Cost in US dollars × 1,000,000 for the given usage. */
    public function costMicro(string $provider, string $model, int $in, int $out): int
    {
        [$pin, $pout] = $this->all()['prices']["{$provider}:{$model}"] ?? [0.0, 0.0];

        return (int) round($in * (float) $pin + $out * (float) $pout); // per-million price × tokens = micro-dollars
    }
}
