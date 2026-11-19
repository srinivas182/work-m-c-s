<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Credits\Events\LowCreditBalance;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Push\Jobs\SendPush;
use Wurk\Push\Models\PushSubscription;
use Wurk\Push\Support\WebPush;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistConversation;

/*
 * Sprint 26.2: the WURK app's push notifications. Messages are encrypted for each phone (RFC 8291) and signed with
 * WURK's own VAPID key (RFC 8292). Each person turns them on per phone and chooses what they're told about per
 * business; only people allowed to see something are told, never whoever did it.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

/** What the browser does: decrypts a push with its own private key and secret. */
function decryptPush(string $body, OpenSSLAsymmetricKey $uaPrivate, string $authSecret): string
{
    $salt = substr($body, 0, 16);
    $idLen = ord($body[20]);
    $asPublic = substr($body, 21, $idLen);
    $cipher = substr($body, 21 + $idLen);
    $uaPublic = WebPush::point($uaPrivate);
    $secret = openssl_pkey_derive(WebPush::publicKeyFromPoint($asPublic), $uaPrivate, 32);
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));

    return rtrim((string) $plain, "\x02");
}

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->stylist = $this->person(['phone' => '+27831000002', 'email' => 'lindiwe@glow.co.za']);
    $this->cashier = $this->person(['phone' => '+27831000003', 'email' => 'sam@glow.co.za']);
    foreach ([$this->owner, $this->stylist, $this->cashier] as $p) {
        $this->member($p, $this->glow);
    }
    [$this->lindiwe] = ($this->in)(fn () => [(string) app(Team::class)->add($this->stylist->id, 'Lindiwe', 'staff')->id,
        app(Team::class)->add($this->owner->id, 'Zodwa', 'owner'), app(Team::class)->add($this->cashier->id, 'Sam', 'staff')]);
    $this->phone = function (object $person): array {
        $key = WebPush::newKey();
        $secret = random_bytes(16);
        PushSubscription::query()->create(['identity_id' => $person->id, 'endpoint_hash' => hash('sha256', 'https://fcm.googleapis.com/fcm/send/'.$person->id),
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$person->id, 'p256dh' => WebPush::b64e(WebPush::point($key)), 'auth' => WebPush::b64e($secret)]);

        return [$key, $secret];
    };
});

it('encrypts each message for the phone and signs it with WURK’s own VAPID key', function () {
    [$key, $secret] = ($this->phone)($this->owner);
    $sub = PushSubscription::query()->sole();
    Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);

    expect(app(WebPush::class)->send($sub->endpoint, $sub->p256dh, $sub->auth, '{"title":"New booking"}'))->toBe(201);
    Http::assertSent(function ($request) use ($key, $secret) {
        [$t, $k] = sscanf($request->header('Authorization')[0], 'vapid t=%[^,], k=%s');
        [$h, $c, $sig] = explode('.', (string) $t);
        $claims = json_decode(WebPush::b64d($c), true);
        $raw = WebPush::b64d($sig);
        $public = WebPush::publicKeyFromPoint(WebPush::b64d((string) $k));
        $validSignature = strlen($raw) === 64 && openssl_verify("{$h}.{$c}", self_der($raw), $public, OPENSSL_ALGO_SHA256) === 1;

        return $request->header('Content-Encoding')[0] === 'aes128gcm' && $claims['aud'] === 'https://fcm.googleapis.com'
            && $claims['sub'] === 'https://'.config('wurk.domains.root') && $validSignature
            && decryptPush($request->body(), $key, $secret) === '{"title":"New booking"}';
    });
    expect(DB::table('push_vapid_keys')->value('public_key'))->toBe(app(WebPush::class)->publicKey()) // made once, then kept
        ->and(DB::table('push_vapid_keys')->value('private_key'))->not->toContain('PRIVATE KEY'); // encrypted
});

it('tells the booked stylist and the people who manage bookings — not the cashier, not whoever made it, not what they switched off', function () {
    foreach ([$this->owner, $this->stylist, $this->cashier] as $p) {
        ($this->phone)($p);
    }
    Bus::fake([SendPush::class]);
    ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $this->lindiwe, 'tenant_id' => $this->glow->id]);
        app(Booker::class)->book($service, CarbonImmutable::now('Africa/Johannesburg')->addDay()->setTime(10, 0), ['name' => 'Thandi Mokoena'], $this->lindiwe, 'website', asOwner: true);
    });
    $to = fn () => Bus::dispatched(SendPush::class)->map(fn (SendPush $j) => PushSubscription::query()->find($j->subscriptionId)?->identity_id)->sort()->values()->all();
    expect($to())->toBe(collect([$this->owner->id, $this->stylist->id])->sort()->values()->all());
    $payload = json_decode(Bus::dispatched(SendPush::class)->first()->payload, true);
    expect($payload['title'])->toBe('New booking: Thandi Mokoena')->and($payload['body'])->toContain('Knotless braids')->toContain('with Lindiwe')
        ->and($payload['business'])->toBe('Glow Salon')->and($payload['tag'])->toBe('booking');

    // Lindiwe switches bookings off for herself; the owner makes the next one, so isn't told about it.
    $this->actingAs($this->stylist)->put($this->app('/glow-salon/settings/notifications'), ['off' => ['booking']])->assertSessionHas('status', 'Saved.');
    Bus::fake([SendPush::class]);
    ($this->in)(fn () => app(PushNotifications::class)->notify('booking', 'New booking', 'x', '/', 'bookings.manage', [$this->stylist->id], $this->owner->id));
    Bus::assertNotDispatched(SendPush::class);
});

it('lets each person turn notifications on for a phone, send a test, and turn them off; a phone the push service forgot is removed', function () {
    $page = $this->app('/glow-salon/settings/notifications');
    $this->actingAs($this->stylist)->get($page)->assertInertia(fn (Assert $p) => $p->component('Push::Settings')->where('phones', 0)->where('off', [])
        ->where('publicKey', app(WebPush::class)->publicKey())->has('kinds', 6));
    $key = WebPush::newKey();
    $sub = ['endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/abc', 'keys' => ['p256dh' => WebPush::b64e(WebPush::point($key)), 'auth' => WebPush::b64e(random_bytes(16))]];
    $this->actingAs($this->stylist)->postJson($page.'/phones', [...$sub, 'keys' => ['p256dh' => 'short', 'auth' => 'x']])->assertUnprocessable();
    $this->actingAs($this->stylist)->postJson($page.'/phones', [...$sub, 'device' => 'Android'])->assertOk()->assertJson(['phones' => 1]);
    $this->actingAs($this->stylist)->postJson($page.'/phones', $sub)->assertJson(['phones' => 1]); // the same phone again

    Http::fake(['updates.push.services.mozilla.com/*' => Http::sequence()->push('', 201)->push('', 410)]);
    $this->actingAs($this->stylist)->post($page.'/test')->assertSessionHas('status', 'Sent. It should arrive in a few seconds.');
    expect(PushSubscription::query()->sole()->last_sent_at)->not->toBeNull();
    $this->actingAs($this->stylist)->post($page.'/test');
    expect(PushSubscription::query()->count())->toBe(0); // 410 Gone: forgotten

    $this->actingAs($this->stylist)->postJson($page.'/phones', $sub);
    $this->actingAs($this->owner)->postJson($page.'/phones/remove', ['endpoint' => $sub['endpoint']])->assertJson(['phones' => 0]);
    expect(PushSubscription::query()->count())->toBe(1); // only its owner can remove it
    $this->actingAs($this->stylist)->postJson($page.'/phones/remove', ['endpoint' => $sub['endpoint']])->assertJson(['phones' => 0]);
});

/** DER for a raw r||s signature, with the leading zero each integer may need. */
function self_der(string $raw): string
{
    $int = function (string $b): string {
        $b = ltrim($b, "\0");
        if ($b === '' || ord($b[0]) & 0x80) {
            $b = "\0".$b;
        }

        return "\x02".chr(strlen($b)).$b;
    };
    $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

    return "\x30".chr(strlen($seq)).$seq;
}

it('buzzes for a chat handed to a person, money arriving online, and credits running low', function () {
    ($this->phone)($this->owner);
    Bus::fake([SendPush::class]);
    $titles = fn () => Bus::dispatched(SendPush::class)->map(fn (SendPush $j) => json_decode($j->payload, true)['title'])->values()->all();
    ($this->in)(function () {
        $chat = ReceptionistConversation::query()->create(['channel' => 'website', 'visitor' => str_repeat('a', 64), 'status' => 'ai', 'last_message_at' => now()]);
        $chat->update(['status' => 'waiting', 'name' => 'Thandi']);
        $chat->update(['name' => 'Thandi M.']); // still waiting: not told twice
        $a = PaymentAttempt::query()->create(['reference' => 'shop-1', 'provider' => 'paystack', 'amount_cents' => 45000, 'status' => 'pending']);
        $a->update(['status' => 'paid', 'paid_at' => now()]);
        event(new LowCreditBalance($this->glow->id, 4500, 10000));
    });
    expect($titles())->toBe(['A chat is waiting for you', 'Payment received: R450.00', 'Credits running low']);
});
