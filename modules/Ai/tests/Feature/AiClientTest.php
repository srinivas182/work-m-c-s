<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Models\AiRequest;
use Wurk\Ai\Support\AiSettings;
use Wurk\Messaging\Support\ProviderSettings;

beforeEach(function () {
    SimulatedDriver::$failNext = 0;
    SimulatedDriver::$nextAnswer = null;
});

function aiKey(string $provider): void
{
    app(ProviderSettings::class)->save('ai.'.$provider, $provider, ['api_key' => "key-{$provider}"], [], true, null);
}

it('sends Anthropic the documented request and reads its reply and usage', function () {
    aiKey('anthropic');
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Hello']], 'usage' => ['input_tokens' => 6000, 'output_tokens' => 6000]], 200)]);

    expect(app(Ai::class)->run('site_generation', 'You write websites.', 'Glow Salon'))->toBe('Hello');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.anthropic.com/v1/messages'
        && $r->hasHeader('x-api-key', 'key-anthropic') && $r->hasHeader('anthropic-version', '2023-06-01')
        && $r['model'] === 'claude-sonnet-5-5' && $r['system'] === 'You write websites.' && $r['messages'][0]['content'] === 'Glow Salon');

    // $2 / M in + $10 / M out, 6,000 each = $0.012 + $0.06 = $0.072
    expect(AiRequest::query()->firstOrFail()->only(['provider', 'model', 'status', 'cost_usd_micro', 'fallback']))
        ->toBe(['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'status' => 'succeeded', 'cost_usd_micro' => 72000, 'fallback' => false]);
});

it('reads Google and OpenAI replies, counting Gemini thinking tokens as output', function () {
    aiKey('google');
    aiKey('openai');
    app(AiSettings::class)->save([...AiSettings::DEFAULTS, 'tasks' => ['rewrite' => ['provider' => 'google', 'model' => 'gemini-3.8-flash'], 'site_generation' => ['provider' => 'openai', 'model' => 'gpt-6-sol']], 'fallback' => []], null);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Gem']]]]], 'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 50, 'thoughtsTokenCount' => 30]], 200),
        'api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Open']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]], 200),
    ]);

    expect(app(Ai::class)->run('rewrite', 'S', 'P'))->toBe('Gem')
        ->and(app(Ai::class)->run('site_generation', 'S', 'P'))->toBe('Open');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'models/gemini-3.8-flash:generateContent') && $r->hasHeader('x-goog-api-key', 'key-google'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/responses' && $r['instructions'] === 'S' && $r->hasHeader('Authorization', 'Bearer key-openai'));
    expect(AiRequest::query()->where('provider', 'google')->value('output_tokens'))->toBe(80);
});

it('falls back to the next provider when one fails, logging both attempts', function () {
    aiKey('anthropic');
    aiKey('google');
    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => ['message' => 'Overloaded']], 529),
        'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'From Gemini']]]]], 'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1]], 200),
    ]);

    expect(app(Ai::class)->run('site_generation', 'S', 'P'))->toBe('From Gemini');
    expect(AiRequest::query()->orderBy('created_at')->orderBy('id')->get(['provider', 'status', 'fallback', 'error'])->map->only(['provider', 'status', 'fallback'])->all())
        ->toBe([['provider' => 'anthropic', 'status' => 'failed', 'fallback' => false], ['provider' => 'google', 'status' => 'succeeded', 'fallback' => true]]);
});

it('uses the simulated provider only outside production, when no real provider is set', function () {
    expect(app(Ai::class)->run('rewrite', 'S', 'P'))->toBe('Simulated answer.');

    SimulatedDriver::$failNext = 1;
    expect(fn () => app(Ai::class)->run('rewrite', 'S', 'P'))->toThrow(AiUnavailable::class);

    app()->detectEnvironment(fn () => 'production');
    expect(fn () => app(Ai::class)->run('rewrite', 'S', 'P'))->toThrow(AiUnavailable::class);
});

it('stops at the daily limit per business and the monthly spend cap per provider', function () {
    app(AiSettings::class)->save([...AiSettings::DEFAULTS, 'caps' => ['tenant_daily_requests' => 2, 'provider_monthly_usd' => []]], null);
    app(Ai::class)->run('rewrite', 'S', 'P', tenantId: 'T1');
    app(Ai::class)->run('rewrite', 'S', 'P', tenantId: 'T1');
    expect(fn () => app(Ai::class)->run('rewrite', 'S', 'P', tenantId: 'T1'))->toThrow(AiUnavailable::class, 'today’s limit')
        ->and(app(Ai::class)->run('rewrite', 'S', 'P', tenantId: 'T2'))->toBe('Simulated answer.');

    aiKey('anthropic');
    app(AiSettings::class)->save([...AiSettings::DEFAULTS, 'caps' => ['tenant_daily_requests' => 0, 'provider_monthly_usd' => ['anthropic' => 0.05]]], null);
    AiRequest::query()->create(['task' => 'x', 'provider' => 'anthropic', 'model' => 'm', 'cost_usd_micro' => 60000, 'status' => 'succeeded', 'created_at' => now()]);
    expect(fn () => app(Ai::class)->run('rewrite', 'S', 'P'))->toThrow(AiUnavailable::class, 'paused');
});
