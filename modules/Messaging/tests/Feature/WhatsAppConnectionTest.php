<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 21.1: a business connects its own WhatsApp number through Meta's Embedded Signup (WURK as Tech Provider),
 * keeping the WhatsApp Business app by default. Meta's Graph API is faked; nothing waits on Meta's approvals.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

const GRAPH = 'https://graph.facebook.com/v23.0';

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->owner = $this->person(['email' => 'thandi@glow.co.za']);
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Thandi', 'owner'));
    $this->base = $this->app('/glow-salon/settings/whatsapp');
    $this->switchOn = fn () => app(ProviderSettings::class)->save('whatsapp', 'meta', ['access_token' => 'system-token', 'app_secret' => 'app-secret'],
        ['phone_number_id' => '100', 'app_id' => 'meta-app', 'signup_config_id' => 'cfg-9'], true, null);
    $this->meta = fn (array $extra = []) => Http::fake([
        GRAPH.'/oauth/access_token*' => Http::response(['access_token' => 'biz-token', 'token_type' => 'bearer']),
        GRAPH.'/debug_token*' => Http::response(['data' => ['granular_scopes' => [
            ['scope' => 'whatsapp_business_management', 'target_ids' => ['waba-1']], ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['waba-1']]]]]),
        GRAPH.'/waba-1/phone_numbers*' => Http::response(['data' => [['id' => 'pn-1', 'display_phone_number' => '+27 82 555 0101', 'verified_name' => 'Glow Salon',
            'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'name_status' => 'APPROVED', 'platform_type' => 'CLOUD_API']]]),
        GRAPH.'/waba-1/subscribed_apps' => Http::response(['success' => true]),
        GRAPH.'/pn-1/register' => Http::response(['success' => true]),
        ...$extra,
    ]);
    $this->start = function (bool $coexistence = true): array {
        $to = (string) $this->actingAs($this->owner)->post("{$this->base}/connect", ['coexistence' => $coexistence])->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($to, PHP_URL_QUERY), $q);

        return [$to, $q];
    };
});

it('says it’s coming soon until WURK’s Meta app is set up', function () {
    $this->actingAs($this->owner)->get($this->base)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Messaging::WhatsApp')->where('available', false)->where('connection', null));
    $this->actingAs($this->owner)->post("{$this->base}/connect")->assertNotFound();
});

it('connects the owner’s number with Embedded Signup, keeping the WhatsApp Business app, with the token encrypted', function () {
    ($this->switchOn)();
    ($this->meta)();
    [$to, $q] = ($this->start)();
    expect($to)->toStartWith('https://www.facebook.com/v23.0/dialog/oauth')
        ->and($q)->toMatchArray(['client_id' => 'meta-app', 'config_id' => 'cfg-9', 'response_type' => 'code', 'redirect_uri' => $this->app('/whatsapp/connected')])
        ->and(json_decode($q['extras'], true))->toMatchArray(['featureType' => 'whatsapp_business_app_onboarding', 'sessionInfoVersion' => '3']);

    $this->actingAs($this->owner)->get($this->app('/whatsapp/connected').'?code=good&state='.urlencode($q['state']))
        ->assertRedirect('/glow-salon/settings/whatsapp')->assertSessionHas('status', 'WhatsApp is connected (+27 82 555 0101). Customers’ messages now come to Chats.');

    $c = WhatsAppConnection::query()->sole();
    expect($c->only(['tenant_id', 'waba_id', 'phone_number_id', 'verified_name', 'quality', 'name_status', 'coexistence', 'status']))
        ->toBe(['tenant_id' => $this->glow->id, 'waba_id' => 'waba-1', 'phone_number_id' => 'pn-1', 'verified_name' => 'Glow Salon', 'quality' => 'GREEN',
            'name_status' => 'APPROVED', 'coexistence' => true, 'status' => 'connected'])
        ->and($c->token)->toBe('biz-token')
        ->and(DB::table('whatsapp_connections')->value('token'))->not->toContain('biz-token'); // encrypted at rest
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/waba-1/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer biz-token'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/register')); // the app's number is already registered

    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->where('available', true)
        ->where('connection.display_phone', '+27 82 555 0101')->where('connection.coexistence', true)->missing('connection.token'));
});

it('registers a new number with a two-step PIN when the owner isn’t keeping the app', function () {
    ($this->switchOn)();
    ($this->meta)();
    [, $q] = ($this->start)(false);
    expect(json_decode($q['extras'], true))->not->toHaveKey('featureType');
    $this->actingAs($this->owner)->get($this->app('/whatsapp/connected').'?code=good&state='.urlencode($q['state']))->assertSessionHas('status');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/pn-1/register') && preg_match('/^\d{6}$/', (string) $r['pin']) === 1);
    expect(WhatsAppConnection::query()->sole()->pin)->toMatch('/^\d{6}$/')->and(WhatsAppConnection::query()->sole()->coexistence)->toBeFalse();
});

it('refuses a return that wasn’t started here, by someone else, or after it expired', function () {
    ($this->switchOn)();
    ($this->meta)();
    $forged = Crypt::encryptString((string) json_encode(['tenant' => $this->glow->id, 'user' => $this->owner->id, 'nonce' => 'attacker', 'exp' => now()->addMinutes(5)->getTimestamp()]));
    $this->actingAs($this->owner)->get($this->app('/whatsapp/connected').'?code=x&state='.urlencode($forged))->assertForbidden();
    $this->actingAs($this->owner)->get($this->app('/whatsapp/connected').'?code=x&state=not-encrypted')->assertForbidden();

    [, $q] = ($this->start)();
    $other = $this->person(['email' => 'someone@else.co.za', 'phone' => '+27829990001']);
    $this->actingAs($other)->withSession(['whatsapp_signup' => json_decode(Crypt::decryptString($q['state']), true)['nonce']])
        ->get($this->app('/whatsapp/connected').'?code=x&state='.urlencode($q['state']))->assertForbidden();

    [, $q] = ($this->start)();
    $this->travel(16)->minutes();
    $this->actingAs($this->owner)->get($this->app('/whatsapp/connected').'?code=x&state='.urlencode($q['state']))->assertForbidden();
    expect(WhatsAppConnection::query()->count())->toBe(0);
});

it('explains when the owner cancels or Meta refuses', function () {
    ($this->switchOn)();
    ($this->meta)([GRAPH.'/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid verification code format.']], 400)]);
    [, $q] = ($this->start)();
    $this->actingAs($this->owner)->get($this->app('/whatsapp/connected').'?error=access_denied&state='.urlencode($q['state']))
        ->assertSessionHas('status', 'WhatsApp wasn’t connected. You can try again any time.');

    [, $q] = ($this->start)();
    $this->actingAs($this->owner)->get($this->app('/whatsapp/connected').'?code=bad&state='.urlencode($q['state']))
        ->assertSessionHas('status', 'WhatsApp couldn’t be connected: Invalid verification code format. Please try again.');
    expect(WhatsAppConnection::query()->count())->toBe(0);
});

it('won’t connect a number that’s already connected to another business', function () {
    ($this->switchOn)();
    ($this->meta)();
    $other = $this->createTenant('other-salon');
    WhatsAppConnection::query()->create(['tenant_id' => $other->id, 'waba_id' => 'waba-1', 'phone_number_id' => 'pn-1', 'token' => 't', 'status' => 'connected']);
    [, $q] = ($this->start)();
    $this->actingAs($this->owner)->get($this->app('/whatsapp/connected').'?code=good&state='.urlencode($q['state']))
        ->assertSessionHas('status', 'That WhatsApp number is already connected to another business on WURK.');
    expect(WhatsAppConnection::query()->where('tenant_id', $this->glow->id)->exists())->toBeFalse();
});

it('checks the number with Meta again, and disconnects (forgetting the token)', function () {
    ($this->switchOn)();
    WhatsAppConnection::query()->create(['tenant_id' => $this->glow->id, 'waba_id' => 'waba-1', 'phone_number_id' => 'pn-1', 'token' => 'biz-token', 'status' => 'connected', 'quality' => 'GREEN']);
    ($this->meta)([GRAPH.'/waba-1/phone_numbers*' => Http::response(['data' => [['id' => 'pn-1', 'display_phone_number' => '+27 82 555 0101', 'quality_rating' => 'YELLOW', 'messaging_limit_tier' => 'TIER_10K', 'name_status' => 'APPROVED']]])]);
    $this->actingAs($this->owner)->post("{$this->base}/refresh")->assertSessionHas('status', 'Checked with Meta just now.');
    expect(WhatsAppConnection::query()->sole()->only(['quality', 'messaging_limit']))->toBe(['quality' => 'YELLOW', 'messaging_limit' => 'TIER_10K']);

    $this->actingAs($this->owner)->delete($this->base)->assertSessionHas('status', 'WhatsApp is disconnected. Your number keeps working in the WhatsApp Business app.');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/waba-1/subscribed_apps'));
    expect(WhatsAppConnection::query()->sole()->only(['status', 'token']))->toBe(['status' => 'disconnected', 'token' => '']);
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->where('connection', null));
});

it('is for people who manage messages only', function () {
    ($this->switchOn)();
    $staff = $this->person(['email' => 'sipho@glow.co.za', 'phone' => '+27829990002']);
    $this->member($staff, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($staff->id, 'Sipho', 'staff'));
    expect($this->actingAs($staff)->get($this->base)->status())->toBeIn([403, 404])
        ->and($this->actingAs($staff)->post("{$this->base}/connect")->status())->toBeIn([403, 404]);
});
