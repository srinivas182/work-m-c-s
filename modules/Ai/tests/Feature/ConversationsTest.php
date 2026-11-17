<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Wurk\Ai\Contracts\AiConversations;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Contracts\ChatTool;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Models\AiRequest;
use Wurk\Ai\Support\AiSettings;
use Wurk\Messaging\Support\ProviderSettings;

/** Sprint 19.1: conversations with tools — the engine behind the AI receptionist. */
beforeEach(function () {
    SimulatedDriver::$failNext = 0;
    SimulatedDriver::$script = [];
    SimulatedDriver::$seen = [];
    $this->tools = [new ChatTool('free_times', 'Free times for a service on a day', ['type' => 'object', 'properties' => ['service' => ['type' => 'string'], 'date' => ['type' => 'string']], 'required' => ['service', 'date']])];
    $this->ran = [];
    $this->run = function (string $name, array $args): array {
        $this->ran[] = [$name, $args];

        return ['times' => ['14:00', '15:30']];
    };
});

function chatKey(string $provider): void
{
    app(ProviderSettings::class)->save('ai.'.$provider, $provider, ['api_key' => "key-{$provider}"], [], true, null);
}

it('lets the AI call tools, runs them for it, and returns its answer with the whole turn', function () {
    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['service' => 'Box braids', 'date' => '2026-11-21']], ['text' => 'Saturday has 14:00 or 15:30.']];
    $reply = app(AiConversations::class)->converse('receptionist', 'You are Glow Salon’s receptionist.', [['role' => 'user', 'content' => 'Box braids on Saturday?']], $this->tools, $this->run, 'tenant-1');

    expect($reply->text)->toBe('Saturday has 14:00 or 15:30.')->and($reply->finished)->toBeTrue()
        ->and($this->ran)->toBe([['free_times', ['service' => 'Box braids', 'date' => '2026-11-21']]])
        ->and($reply->calls[0]['result'])->toBe(['times' => ['14:00', '15:30']])
        ->and(array_column($reply->transcript, 'role'))->toBe(['assistant', 'tool', 'assistant'])
        // The second step saw the tool's result.
        ->and(SimulatedDriver::$seen[1]['items'][2])->toMatchArray(['role' => 'tool', 'name' => 'free_times', 'content' => ['times' => ['14:00', '15:30']]])
        ->and(SimulatedDriver::$seen[0]['tools'])->toBe(['free_times'])
        ->and(AiRequest::query()->where('task', 'receptionist')->where('tenant_id', 'tenant-1')->count())->toBe(2); // every step logged and costed
});

it('stops after the step limit rather than looping', function () {
    SimulatedDriver::$script = array_fill(0, 5, ['tool' => 'free_times', 'arguments' => ['service' => 'x', 'date' => 'y']]);
    $reply = app(AiConversations::class)->converse('receptionist', 'S', [['role' => 'user', 'content' => 'Hi']], $this->tools, $this->run, maxSteps: 3);
    expect($reply->finished)->toBeFalse()->and($this->ran)->toHaveCount(3);
});

it('sends each provider its own tool format and reads its tool calls', function () {
    chatKey('anthropic');
    chatKey('openai');
    chatKey('google');
    $items = [
        ['role' => 'user', 'content' => 'Saturday?'],
        ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'name' => 'free_times', 'arguments' => ['service' => 'Braids', 'date' => '2026-11-21']]]],
        ['role' => 'tool', 'tool_call_id' => 'c1', 'name' => 'free_times', 'content' => ['times' => ['14:00']]],
    ];
    Http::fake([
        'api.anthropic.com/*' => Http::response(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'free_times', 'input' => ['service' => 'Braids', 'date' => '2026-11-22']]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]], 200),
        'api.openai.com/*' => Http::response(['output' => [['type' => 'function_call', 'call_id' => 'fc1', 'name' => 'free_times', 'arguments' => '{"service":"Braids","date":"2026-11-22"}']], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]], 200),
        'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'free_times', 'args' => ['service' => 'Braids', 'date' => '2026-11-22']]]]]]], 'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5]], 200),
    ]);
    foreach (['anthropic' => 'claude-sonnet-5-5', 'openai' => 'gpt-6-sol', 'google' => 'gemini-3.8-flash'] as $provider => $model) {
        app(AiSettings::class)->save([...AiSettings::DEFAULTS, 'tasks' => ['receptionist' => ['provider' => $provider, 'model' => $model]], 'fallback' => []], null);
        $reply = app(AiConversations::class)->converse('receptionist', 'S', $items, $this->tools, $this->run, maxSteps: 1);
        expect($reply->calls[0]['arguments'])->toBe(['service' => 'Braids', 'date' => '2026-11-22']);
    }
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic') && $r['tools'][0]['input_schema']['required'] === ['service', 'date']
        && $r['messages'][1]['content'][0]['type'] === 'tool_use' && $r['messages'][2]['content'][0] === ['type' => 'tool_result', 'tool_use_id' => 'c1', 'content' => '{"times":["14:00"]}']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openai') && $r['tools'][0]['type'] === 'function'
        && $r['input'][1] === ['type' => 'function_call', 'call_id' => 'c1', 'name' => 'free_times', 'arguments' => '{"service":"Braids","date":"2026-11-21"}']
        && $r['input'][2] === ['type' => 'function_call_output', 'call_id' => 'c1', 'output' => '{"times":["14:00"]}']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage') && $r['tools'][0]['functionDeclarations'][0]['name'] === 'free_times'
        && $r['contents'][1]['role'] === 'model' && isset($r['contents'][1]['parts'][0]['functionCall']) && isset($r['contents'][2]['parts'][0]['functionResponse']));
});

it('hands the conversation to the next provider mid-turn, never running a tool twice', function () {
    chatKey('anthropic');
    chatKey('openai');
    app(AiSettings::class)->save([...AiSettings::DEFAULTS, 'tasks' => ['receptionist' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']], 'fallback' => [['provider' => 'openai', 'model' => 'gpt-6-sol']]], null);
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'free_times', 'input' => ['service' => 'Braids', 'date' => '2026-11-21']]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200)
            ->push(['error' => ['message' => 'Overloaded']], 529),
        'api.openai.com/*' => Http::response(['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '14:00 or 15:30.']]]], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]], 200),
    ]);
    $reply = app(AiConversations::class)->converse('receptionist', 'S', [['role' => 'user', 'content' => 'Saturday?']], $this->tools, $this->run);
    expect($reply->text)->toBe('14:00 or 15:30.')->and($this->ran)->toHaveCount(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openai') && $r['input'][2]['type'] === 'function_call_output'); // the transcript carried over
});

it('gives up politely when every provider fails', function () {
    SimulatedDriver::$failNext = 1;
    expect(fn () => app(AiConversations::class)->converse('receptionist', 'S', [['role' => 'user', 'content' => 'Hi']], [], $this->run))
        ->toThrow(AiUnavailable::class);
});
