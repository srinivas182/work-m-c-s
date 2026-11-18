<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Messaging\Models\ProviderSetting;
use Wurk\Messaging\Support\ProviderSettings;

uses(InteractsWithIdentity::class);

/** Sprint 19.6: every key in Admin → Providers has a "Test connection" that tries it without sending or charging anything. */
beforeEach(function () {
    $this->admin = $this->staffWithRole('super-admin', 'admin@wurk.co.za');
    $this->test = fn (string $channel) => $this->actingAs($this->admin)->post($this->admin("/providers/{$channel}/test"));
});

it('tries AI and Paystack keys with a read-only request, and says plainly when one is refused', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['data' => []]),
        'api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401),
        'generativelanguage.googleapis.com/*' => Http::response([], 500),
        'api.paystack.co/balance' => Http::response(['status' => true, 'data' => []]),
    ]);
    app(ProviderSettings::class)->save('ai.anthropic', 'anthropic', ['api_key' => 'sk-ant-good'], [], true, null);
    app(ProviderSettings::class)->save('ai.openai', 'openai', ['api_key' => 'sk-bad'], [], false, null);
    app(ProviderSettings::class)->save('ai.google', 'google', ['api_key' => 'g-key'], [], false, null);
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test_1'], [], true, null);

    ($this->test)('ai.anthropic')->assertSessionHas('status', 'Connected — the keys work.');
    ($this->test)('ai.openai')->assertSessionHas('status', 'Test failed: OpenAI refused the key. Check it was copied in full.');
    ($this->test)('ai.google')->assertSessionHas('status', 'Test failed: Google didn’t answer as expected (HTTP 500). Try again shortly.');
    ($this->test)('payments.paystack')->assertSessionHas('status', 'Connected — the keys work.'); // no "send to" needed (it used to be required)

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com/v1/models') && $r->method() === 'GET' && $r->header('x-api-key') === ['sk-ant-good']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generativelanguage.googleapis.com') && ! str_contains($r->url(), 'g-key')); // never the key in the address
    expect(ProviderSetting::query()->find('ai.anthropic'))->last_test_ok->toBeTrue()->last_test_message->toBe('Connected')
        ->and(ProviderSetting::query()->find('ai.openai'))->last_test_ok->toBeFalse();
});

it('checks meeting apps’ client ID and secret without anyone signing in', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        'zoom.us/oauth/token' => Http::response(['reason' => 'Invalid client_id or client_secret', 'error' => 'invalid_client'], 401),
    ]);
    app(ProviderSettings::class)->save('meetings.google', 'google', ['client_id' => 'id.apps.googleusercontent.com', 'client_secret' => 'secret'], [], true, null);
    app(ProviderSettings::class)->save('meetings.zoom', 'zoom', ['client_id' => 'zid', 'client_secret' => 'wrong'], [], true, null);

    ($this->test)('meetings.google')->assertSessionHas('status', 'Connected — the keys work.');
    ($this->test)('meetings.zoom')->assertSessionHas('status', 'Test failed: Zoom didn’t accept the client ID and secret.');
});

it('still needs an address for a message provider’s test, and no longer lists keys that nothing used', function () {
    app(ProviderSettings::class)->save('sms', 'smsportal', ['client_id' => 'cid', 'api_secret' => 'secret'], [], true, null);
    ($this->test)('sms')->assertSessionHasErrors('to');
    $this->actingAs($this->admin)->get($this->admin('/providers'))->assertInertia(fn (Assert $p) => $p->where('channels', fn ($channels) => ! collect($channels)->pluck('channel')
        ->intersect(['payments.payfast', 'payments.yoco', 'payments.ozow', 'payments.peach'])->isNotEmpty() && collect($channels)->pluck('channel')->contains('payments.paystack')));
});
