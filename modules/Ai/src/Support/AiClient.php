<?php

declare(strict_types=1);

namespace Wurk\Ai\Support;

use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiConversations;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Contracts\ChatReply;
use Wurk\Ai\Drivers\AiDriver;
use Wurk\Ai\Drivers\AnthropicDriver;
use Wurk\Ai\Drivers\ChatDriver;
use Wurk\Ai\Drivers\GoogleDriver;
use Wurk\Ai\Drivers\OpenAiDriver;
use Wurk\Ai\Drivers\OpenAiImageDriver;
use Wurk\Ai\Drivers\ProviderFailed;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Drivers\SimulatedImageDriver;
use Wurk\Ai\Models\AiRequest;
use Wurk\Messaging\Contracts\ProviderCredentials;

/**
 * The task's model first, then the fallback order; providers without keys are
 * skipped. Every attempt is logged with its real cost. Outside production, with
 * no real provider configured, the simulated provider answers.
 */
final readonly class AiClient implements Ai, AiConversations
{
    public function __construct(private AiSettings $settings, private ProviderCredentials $providers) {}

    public function run(string $task, string $system, string $prompt, int $maxTokens = 4000, ?string $tenantId = null): string
    {
        $this->checkCaps($tenantId);
        $chain = array_values(array_filter($this->settings->chain($task), fn (array $step) => $this->key($step['provider']) !== null));
        if ($chain === [] && ! app()->environment('production')) {
            $chain = [['provider' => 'simulated', 'model' => 'simulated']];
        }

        foreach ($chain as $i => $step) {
            $started = microtime(true);
            try {
                $result = $this->driver($step['provider'])->complete($step['model'], $system, $prompt, $maxTokens, (string) $this->key($step['provider']));
                $this->log($tenantId, $task, $step, $result['input_tokens'], $result['output_tokens'], 'succeeded', null, $i > 0, $started);

                return $result['text'];
            } catch (ProviderFailed $e) {
                $this->log($tenantId, $task, $step, 0, 0, 'failed', $e->getMessage(), $i > 0, $started);
            }
        }

        throw new AiUnavailable('WURK AI is busy right now. Please try again in a few minutes.');
    }

    public function health(string $task, \DateTimeInterface $from, \DateTimeInterface $to, int $slowMs): array
    {
        $r = AiRequest::query()->where('task', $task)->where('created_at', '>=', $from)->where('created_at', '<', $to)->toBase()
            ->selectRaw("count(*) as steps, sum(case when status = 'failed' then 1 else 0 end) as failed, sum(case when duration_ms > ? then 1 else 0 end) as slow, max(duration_ms) as slowest", [$slowMs])->first();

        return ['steps' => (int) ($r->steps ?? 0), 'failed' => (int) ($r->failed ?? 0), 'slow' => (int) ($r->slow ?? 0), 'slowest_ms' => (int) ($r->slowest ?? 0)];
    }

    public function converse(string $task, string $system, array $items, array $tools, callable $runTool, ?string $tenantId = null, int $maxSteps = 6, int $maxTokens = 1200): ChatReply
    {
        $this->checkCaps($tenantId);
        $chain = array_values(array_filter($this->settings->chain($task), fn (array $step) => $this->key($step['provider']) !== null));
        if ($chain === [] && ! app()->environment('production')) {
            $chain = [['provider' => 'simulated', 'model' => 'simulated']];
        }
        $new = [];
        $calls = [];
        $provider = 0;
        for ($step = 0; $step < $maxSteps; $step++) {
            $result = null;
            while ($result === null) {
                $use = $chain[$provider] ?? throw new AiUnavailable('WURK AI is busy right now. Please try again in a few minutes.');
                $driver = $this->driver($use['provider']);
                $started = microtime(true);
                try {
                    if (! $driver instanceof ChatDriver) {
                        throw new ProviderFailed($use['provider'].' can’t hold a conversation');
                    }
                    $result = $driver->chat($use['model'], $system, [...$items, ...$new], $tools, $maxTokens, (string) $this->key($use['provider']));
                    $this->log($tenantId, $task, $use, $result['input_tokens'], $result['output_tokens'], 'succeeded', null, $provider > 0, $started);
                } catch (ProviderFailed $e) {
                    // The next provider carries on from the same transcript: tools already run are never run again.
                    $this->log($tenantId, $task, $use, 0, 0, 'failed', $e->getMessage(), $provider > 0, $started);
                    $provider++;
                }
            }
            if ($result['tool_calls'] === []) {
                $new[] = ['role' => 'assistant', 'content' => $result['text']];

                return new ChatReply($result['text'], $new, $calls);
            }
            $new[] = ['role' => 'assistant', 'content' => $result['text'], 'tool_calls' => $result['tool_calls']];
            foreach ($result['tool_calls'] as $call) {
                $output = $runTool($call['name'], $call['arguments']);
                $calls[] = ['name' => $call['name'], 'arguments' => $call['arguments'], 'result' => $output];
                $new[] = ['role' => 'tool', 'tool_call_id' => $call['id'], 'name' => $call['name'], 'content' => $output];
            }
        }

        return new ChatReply('', $new, $calls, finished: false);
    }

    /** The daily cap is for paid AI work; "Ask WURK" has its own monthly allowance and cap (16.2). */
    private function checkCaps(?string $tenantId): void
    {
        $caps = (array) $this->settings->all()['caps'];
        if ($tenantId !== null && (int) ($caps['tenant_daily_requests'] ?? 0) > 0
            && AiRequest::query()->where('tenant_id', $tenantId)->where('status', 'succeeded')->where('task', '!=', 'assistant')->where('created_at', '>=', now()->startOfDay())->count() >= (int) $caps['tenant_daily_requests']) {
            throw new AiUnavailable('You’ve reached today’s limit for WURK AI. It resets tomorrow.');
        }
        foreach ((array) ($caps['provider_monthly_usd'] ?? []) as $provider => $usd) {
            if ((float) $usd > 0 && AiRequest::query()->where('provider', $provider)->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd_micro') >= (float) $usd * 1_000_000) {
                throw new AiUnavailable('WURK AI is paused for maintenance. Please try again later.');
            }
        }
    }

    private function key(string $provider): ?string
    {
        if ($provider === 'simulated') {
            return app()->environment('production') ? null : 'simulated';
        }
        $key = $this->providers->active('ai.'.$provider)['credentials']['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function driver(string $provider): AiDriver
    {
        return match ($provider) {
            'anthropic' => new AnthropicDriver,
            'google' => new GoogleDriver,
            'openai' => new OpenAiDriver,
            default => new SimulatedDriver,
        };
    }

    /** @param  array{provider: string, model: string}  $step */
    private function log(?string $tenantId, string $task, array $step, int $in, int $out, string $status, ?string $error, bool $fallback, float $started): void
    {
        AiRequest::query()->create([
            'tenant_id' => $tenantId, 'task' => $task, 'provider' => $step['provider'], 'model' => $step['model'],
            'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd_micro' => $this->settings->costMicro($step['provider'], $step['model'], $in, $out),
            'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 255), 'fallback' => $fallback,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'created_at' => now(),
        ]);
    }

    public function image(string $prompt, ?string $tenantId = null): string
    {
        $this->checkCaps($tenantId);
        $chain = array_values(array_filter($this->settings->imageChain(), fn (array $step) => $this->key($step['provider']) !== null));
        if ($chain === [] && ! app()->environment('production')) {
            $chain = [['provider' => 'simulated', 'model' => 'simulated']];
        }
        // Never real, identifiable people; decorative scenes only.
        $prompt = mb_substr($prompt, 0, 400).'. A decorative, photographic-style image for a small business website. Do not depict any real, identifiable person, logo or brand.';

        foreach ($chain as $i => $step) {
            $started = microtime(true);
            try {
                $driver = match ($step['provider']) {
                    'openai' => new OpenAiImageDriver, default => new SimulatedImageDriver
                };
                $bytes = $driver->generate($step['model'], $prompt, (string) $this->key($step['provider']));
                AiRequest::query()->create([
                    'tenant_id' => $tenantId, 'task' => 'image', 'provider' => $step['provider'], 'model' => $step['model'],
                    'cost_usd_micro' => $this->settings->imageCostMicro($step['provider'], $step['model']), 'status' => 'succeeded',
                    'fallback' => $i > 0, 'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'created_at' => now(),
                ]);

                return $bytes;
            } catch (ProviderFailed $e) {
                $this->log($tenantId, 'image', $step, 0, 0, 'failed', $e->getMessage(), $i > 0, $started);
            }
        }

        throw new AiUnavailable('WURK AI couldn’t make an image right now. Please try again in a few minutes.');
    }
}
