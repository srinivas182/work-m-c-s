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

    public const TASKS = ['site_generation' => 'Write a website', 'rewrite' => 'Rewrite a section', 'assistant' => 'Ask WURK (assistant)', 'watchtower' => 'Watchtower (explains platform problems)', 'receptionist' => 'AI receptionist (answers and books for a business)', 'sales' => 'WURK’s sales assistant (wurk.co.za)', 'slip' => 'Reads expense slips (photos)'];

    /** Image providers (Google is added once WURK's key lets us verify its API). */
    public const IMAGE_PROVIDERS = ['openai' => 'OpenAI', 'simulated' => 'Simulated (development only)'];

    /** Speech-to-text for voice notes (21.4). Anthropic has none. */
    public const SPEECH_PROVIDERS = ['openai' => 'OpenAI', 'google' => 'Google (Gemini)', 'simulated' => 'Simulated (development only)'];

    public const DEFAULTS = [
        'tasks' => [
            'site_generation' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
            'rewrite' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'],
            'assistant' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'],
            // Reading code and traces needs the stronger model; a few calls a day at most (Sprint 18.0b).
            'watchtower' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
            // Sprint 19 (decision A): strong tool use for booking; OpenAI is next in the fallback order.
            'receptionist' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5'],
            'sales' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'],
            'slip' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5'], // 23.5b
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
        // Images: model and fallback, priced per image (US dollars) — placeholders until WURK's plan is known.
        'image' => ['provider' => 'openai', 'model' => 'gpt-image-1'],
        'image_fallback' => [],
        'image_prices' => ['openai:gpt-image-1' => 0.04, 'simulated:simulated' => 0.0],
        // Voice notes (21.4, decision C): model and fallback, priced per minute of audio (US dollars) — placeholders until confirmed.
        'speech' => ['provider' => 'openai', 'model' => 'gpt-4o-transcribe'],
        'speech_fallback' => [['provider' => 'google', 'model' => 'gemini-3.8-flash']],
        'speech_prices' => ['openai:gpt-4o-transcribe' => 0.006, 'openai:gpt-4o-mini-transcribe' => 0.003, 'openai:whisper-1' => 0.006, 'google:gemini-3.8-flash' => 0.002, 'simulated:simulated' => 0.0],
        // "Ask WURK" (16.2, decision A): free questions a month per business, then the rate card's "ai.assistant" price each;
        // actions are free; no business asks more than the monthly cap.
        'assistant' => ['enabled' => true, 'free_questions' => 50, 'monthly_cap' => 500, 'whatsapp_daily_cap' => 50], // 26.1: instructions on WhatsApp, per business per day
        // WURK's sales assistant on wurk.co.za (19.5): off until HQ switches it on; WURK pays, within this monthly cap.
        'sales' => ['enabled' => false, 'greeting' => 'Hi! I’m WURK’s AI assistant. Ask me about packages, prices and what WURK can do for your business.', 'monthly_cap' => 2000],
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

    /** @return list<array{provider: string, model: string}> */
    public function imageChain(): array
    {
        $all = $this->all();
        $chain = [(array) $all['image']];
        foreach ((array) $all['image_fallback'] as $f) {
            if ($f !== $chain[0]) {
                $chain[] = (array) $f;
            }
        }

        /** @var list<array{provider: string, model: string}> $chain */
        return $chain;
    }

    /** @return list<array{provider: string, model: string}> */
    public function speechChain(): array
    {
        $all = $this->all();
        $chain = [(array) $all['speech']];
        foreach ((array) $all['speech_fallback'] as $f) {
            if ((array) $f !== $chain[0]) {
                $chain[] = (array) $f;
            }
        }

        /** @var list<array{provider: string, model: string}> $chain */
        return $chain;
    }

    /** A voice note's cost in US dollars × 1,000,000 (a minute is charged when the length isn't known). */
    public function speechCostMicro(string $provider, string $model, ?float $seconds): int
    {
        return (int) round((float) ($this->all()['speech_prices']["{$provider}:{$model}"] ?? 0) * max(1.0, ($seconds ?? 60.0) / 60) * 1_000_000);
    }

    public function imageCostMicro(string $provider, string $model): int
    {
        return (int) round((float) ($this->all()['image_prices']["{$provider}:{$model}"] ?? 0) * 1_000_000);
    }
}
