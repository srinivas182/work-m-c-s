<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Identity\Contracts\Invitations;
use Wurk\Identity\Models\Identity;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Models\MessageDelivery;
use Wurk\Messaging\Models\ProviderSetting;
use Wurk\Messaging\Support\ProviderSettings;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function useSmsPortal(): void
{
    app(ProviderSettings::class)->save('sms', 'smsportal', ['client_id' => 'cid', 'api_secret' => 'super-secret-value'], [], true, null);
}

it('stores secrets encrypted, never shows them back, and never audits their values', function () {
    useSmsPortal();

    $raw = DB::table('provider_settings')->where('channel', 'sms')->value('credentials');
    $presented = app(ProviderSettings::class)->present('sms');

    expect($raw)->not->toContain('super-secret-value')
        ->and(json_encode($presented))->not->toContain('super-secret-value')
        ->and($presented['credentials']['api_secret'])->toBe(['set' => true, 'hint' => '••••alue'])
        ->and(json_encode(DB::table('audit_events')->get()))->not->toContain('super-secret-value');
});

it('keeps a saved secret when the field is left blank, and requires it to switch on', function () {
    useSmsPortal();
    app(ProviderSettings::class)->save('sms', 'smsportal', ['client_id' => 'cid2', 'api_secret' => ''], ['test_mode' => '1'], true, null);
    expect(ProviderSetting::query()->find('sms')->credentials)->toBe(['client_id' => 'cid2', 'api_secret' => 'super-secret-value']);

    expect(fn () => app(ProviderSettings::class)->save('sms', 'twilio', ['account_sid' => 'AC1'], [], true, null))->toThrow(ValidationException::class);
});

it('sends phone sign-in codes by WhatsApp first and falls back to SMS', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400),
        'rest.smsportal.com/*' => Http::response(['eventId' => 77], 200),
    ]);
    useSmsPortal();
    app(ProviderSettings::class)->save('whatsapp', 'meta', ['access_token' => 'EAAG', 'app_secret' => 'as'], ['phone_number_id' => '555', 'otp_template' => 'wurk_sign_in'], true, null);
    $this->person();

    $this->post($this->app('/sign-in/code'), ['identifier' => '082 123 4567'])->assertRedirect();

    expect(MessageDelivery::query()->pluck('status', 'channel')->sortKeys()->all())->toBe(['sms' => 'sent', 'whatsapp' => 'failed']);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'smsportal') && preg_match('/Your WURK code is \d{6}/', $r['messages'][0]['content']) === 1);
    expect(MessageDelivery::query()->where('channel', 'sms')->value('to_masked'))->toBe('+27 ••• ••• 4567');
});

it('writes to the log when no provider is on locally, and records a failure in production', function () {
    $this->person();
    $this->post($this->app('/sign-in/code'), ['identifier' => '082 123 4567']);
    expect(MessageDelivery::query()->value('driver'))->toBe('log');

    app()->detectEnvironment(fn () => 'production');
    app(Messenger::class)->sms('+27821234567', 'x', 'test');
    expect(MessageDelivery::query()->where('purpose', 'test')->firstOrFail()->only(['driver', 'status']))->toBe(['driver' => 'none', 'status' => 'failed']);
});

it('invites a business owner by mobile and lets them accept with a verified number', function () {
    Http::fake(['rest.smsportal.com/*' => Http::response(['eventId' => 1], 200)]);
    useSmsPortal();
    $this->createShards(1);
    $glow = $this->createTenant('glow-salon', name: 'Glow Salon');

    app(Invitations::class)->inviteMember($glow->id, 'Glow Salon', '082 123 4567', 'owner', null, 'Thandi');

    $link = null;
    Http::assertSent(function ($r) use (&$link) {
        preg_match('#https?://\S+/invitations/(\S+)#', $r['messages'][0]['content'] ?? '', $m);
        $link = $m[1] ?? null;

        return $r['messages'][0]['destination'] === '27821234567' && str_contains($r['messages'][0]['content'], 'Glow Salon');
    });

    $this->post($this->app('/invitations/'.$link), ['first_name' => 'Thandi'])->assertRedirect(route('identity.businesses'));
    $person = Identity::query()->where('phone', '+27821234567')->firstOrFail();
    expect($person->phone_verified_at)->not->toBeNull()->and($person->email)->toBeNull();
});

it('accepts signed delivery receipts and rejects forged ones', function () {
    app(ProviderSettings::class)->save('sms', 'twilio', ['account_sid' => 'AC1', 'auth_token' => 'tok'], ['sender' => '+15005550006'], true, null);
    MessageDelivery::query()->create(['channel' => 'sms', 'driver' => 'twilio', 'to_hash' => 'x', 'to_masked' => 'x', 'purpose' => 'test', 'status' => 'sent', 'provider_message_id' => 'SM1']);

    $url = $this->app('/webhooks/messaging/twilio');
    $params = ['MessageSid' => 'SM1', 'MessageStatus' => 'delivered'];
    ksort($params);
    $signature = base64_encode(hash_hmac('sha1', $url.implode('', array_map(fn ($k, $v) => $k.$v, array_keys($params), $params)), 'tok', true));

    $this->post($url, $params, ['X-Twilio-Signature' => 'forged'])->assertForbidden();
    expect(MessageDelivery::query()->value('status'))->toBe('sent');

    $this->post($url, $params, ['X-Twilio-Signature' => $signature])->assertNoContent();
    expect(MessageDelivery::query()->value('status'))->toBe('delivered');
});

it('verifies Meta webhooks and applies signed status updates only', function () {
    app(ProviderSettings::class)->save('whatsapp', 'meta', ['access_token' => 'EAAG', 'app_secret' => 'appsecret'], ['phone_number_id' => '555', 'verify_token' => 'vt-123'], true, null);
    MessageDelivery::query()->create(['channel' => 'whatsapp', 'driver' => 'meta', 'to_hash' => 'x', 'to_masked' => 'x', 'purpose' => 'test', 'status' => 'sent', 'provider_message_id' => 'wamid.9']);

    $this->get($this->app('/webhooks/messaging/meta?hub_mode=subscribe&hub_verify_token=vt-123&hub_challenge=42'))->assertOk()->assertSee('42');
    $this->get($this->app('/webhooks/messaging/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=42'))->assertForbidden();

    $body = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.9', 'status' => 'delivered']]]]]]]]);
    $this->call('POST', $this->app('/webhooks/messaging/meta'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'], $body)->assertForbidden();
    $this->call('POST', $this->app('/webhooks/messaging/meta'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'appsecret')], $body)->assertOk();

    expect(MessageDelivery::query()->value('status'))->toBe('delivered');
});

it('lets only provider managers see providers, and never sends secrets to the browser', function () {
    useSmsPortal();
    $this->actingAs($this->staffWithRole('support'))->get($this->admin('/providers'))->assertForbidden();

    $this->actingAs($this->staffWithRole('super-admin', 'admin@wurk.co.za'))->get($this->admin('/providers'))
        ->assertOk()
        ->assertDontSee('super-secret-value')
        ->assertInertia(fn (Assert $p) => $p->component('Messaging::Admin/Providers')->where('channels.0.current.active', true));
});

it('lets countries be opened for sign-up and only allows businesses in open countries', function () {
    $this->createShards(1);
    $admin = $this->staffWithRole('super-admin');
    $business = ['name' => 'Kalahari Coffee', 'handle' => 'kalahari-coffee', 'owner_first_name' => 'Neo', 'owner_contact' => 'neo@kalahari.co.bw'];

    $this->actingAs($admin)->post($this->admin('/businesses'), $business + ['country' => 'BW'])->assertSessionHasErrors('country');
    $this->actingAs($admin)->put($this->admin('/countries/BW'), ['vat' => 14, 'enabled' => true])->assertSessionHas('status');
    $this->actingAs($admin)->post($this->admin('/businesses'), $business + ['country' => 'BW'])->assertSessionHasNoErrors();
});
