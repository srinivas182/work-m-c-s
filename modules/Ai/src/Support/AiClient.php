<?php

declare(strict_types=1);

namespace Wurk\Ai\Support;

use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Drivers\AiDriver;
use Wurk\Ai\Drivers\AnthropicDriver;
use Wurk\Ai\Drivers\GoogleDriver;
use Wurk\Ai\Drivers\OpenAiDriver;
use Wurk\Ai\Drivers\ProviderFailed;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Models\AiRequest;
use Wurk\Messaging\Contracts\ProviderCredentials;

/**
 * The task's model first, then the fallback order; providers without keys are
 * skipped. Every attempt is logged with its real cost. Outside production, with
 * no real provider configured, the simulated provider answers.
 */
final readonly class AiClient implements Ai
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

    private function checkCaps(?string $tenantId): void
    {
        $caps = (array) $this->settings->all()['caps'];
        if ($tenantId !== null && (int) ($caps['tenant_daily_requests'] ?? 0) > 0
            && AiRequest::query()->where('tenant_id', $tenantId)->where('status', 'succeeded')->where('created_at', '>=', now()->startOfDay())->count() >= (int) $caps['tenant_daily_requests']) {
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
}
