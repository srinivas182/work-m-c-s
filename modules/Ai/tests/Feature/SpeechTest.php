<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithIdentity;
use Wurk\Ai\Contracts\Ai;
use Wurk\Ai\Contracts\AiUnavailable;
use Wurk\Ai\Models\AiRequest;
use Wurk\Ai\Support\AiSettings;
use Wurk\Messaging\Support\ProviderSettings;

/** Sprint 21.4: speech-to-text for customers' voice notes, with the provider and fallback chosen in Admin → AI. */
uses(InteractsWithIdentity::class);

function speechKey(string $provider): void
{
    app(ProviderSettings::class)->save('ai.'.$provider, $provider, ['api_key' => "key-{$provider}"], [], true, null);
}

it('transcribes with OpenAI, judging how sure it is from the words’ probabilities, and logs the cost per minute', function () {
    speechKey('openai');
    Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => ' Sawubona, ngicela ukubhuka ngoMgqibelo. ',
        'logprobs' => [['token' => 'Saw', 'logprob' => -0.05], ['token' => 'ubona', 'logprob' => -0.15]]])]);

    $t = app(Ai::class)->transcribe('ogg-bytes', 'audio/ogg; codecs=opus', 90.0, 'tenant-1');
    expect($t->text)->toBe('Sawubona, ngicela ukubhuka ngoMgqibelo.')->and($t->language)->toBeNull()
        ->and(round((float) $t->confidence, 3))->toBe(round(exp(-0.1), 3))->and($t->unclear())->toBeFalse();
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/audio/transcriptions' && $r->hasHeader('Authorization', 'Bearer key-openai')
        && collect($r->data())->contains(fn ($part) => ($part['name'] ?? '') === 'model' && $part['contents'] === 'gpt-4o-transcribe')
        && collect($r->data())->contains(fn ($part) => ($part['name'] ?? '') === 'file' && $part['filename'] === 'voice-note.ogg'));
    expect(AiRequest::query()->sole()->only(['task', 'provider', 'model', 'cost_usd_micro', 'tenant_id']))
        ->toBe(['task' => 'transcribe', 'provider' => 'openai', 'model' => 'gpt-4o-transcribe', 'cost_usd_micro' => 9000, 'tenant_id' => 'tenant-1']); // 1.5 min × $0.006
});

it('falls back to Gemini, which names the language', function () {
    speechKey('openai');
    speechKey('google');
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'Overloaded']], 503),
        'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"text":"Ek wil Saterdag kom","language":"AF","confidence":0.42}']]]]]]),
    ]);
    $t = app(Ai::class)->transcribe('ogg-bytes', 'audio/ogg', null);
    expect([$t->text, $t->language, $t->confidence, $t->unclear()])->toBe(['Ek wil Saterdag kom', 'af', 0.42, true]);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'models/gemini-3.8-flash:generateContent') && $r['contents'][0]['parts'][0]['inline_data']['mime_type'] === 'audio/ogg'
        && $r['generationConfig']['responseMimeType'] === 'application/json');
    expect(AiRequest::query()->orderBy('created_at')->get(['provider', 'status', 'fallback'])->toArray())
        ->toBe([['provider' => 'openai', 'status' => 'failed', 'fallback' => false], ['provider' => 'google', 'status' => 'succeeded', 'fallback' => true]]);
});

it('says plainly when no provider could listen', function () {
    speechKey('openai');
    speechKey('google');
    Http::fake(['*' => Http::response([], 500)]);
    expect(fn () => app(Ai::class)->transcribe('ogg-bytes', 'audio/ogg'))->toThrow(AiUnavailable::class, 'WURK AI couldn’t listen to that voice note right now.');
});

it('lets HQ choose the speech model, its fallback and prices in Admin → AI', function () {
    $staff = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $settings = [...AiSettings::DEFAULTS, 'speech' => ['provider' => 'google', 'model' => 'gemini-3.8-flash'], 'speech_fallback' => [['provider' => 'openai', 'model' => 'whisper-1']],
        'speech_prices' => [...AiSettings::DEFAULTS['speech_prices'], 'openai:whisper-1' => 0.005]];
    $this->actingAs($staff)->put($this->admin('/settings/ai'), $settings)->assertSessionHasNoErrors();
    expect(app(AiSettings::class)->speechChain())->toBe([['provider' => 'google', 'model' => 'gemini-3.8-flash'], ['provider' => 'openai', 'model' => 'whisper-1']]);

    $this->actingAs($staff)->put($this->admin('/settings/ai'), [...$settings, 'speech' => ['provider' => 'anthropic', 'model' => 'claude-haiku-5-5']])
        ->assertSessionHasErrors('speech.provider'); // Anthropic has no speech-to-text
});
