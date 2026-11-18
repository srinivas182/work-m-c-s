<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Crm\Contracts\Audiences;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Support\Uploads\VirusScanner;

/*
 * Sprint 21.2: customers' WhatsApp messages to a business's own number arrive in Chats; the team replies with text, photos
 * or PDFs inside WhatsApp's 24-hour window. Meta's webhooks are signed and replayed here; the Graph API is faked.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

const WA_GRAPH = 'https://graph.facebook.com/v23.0';

beforeEach(function () {
    Storage::fake('form-files');
    $this->scanner = new class implements VirusScanner
    {
        public ?string $verdict = VirusScanner::CLEAN;

        public function scan(string $contents): ?string
        {
            return $this->verdict;
        }
    };
    app()->instance(VirusScanner::class, $this->scanner);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Owner', 'owner');
        app(CreditWallet::class)->grant('purchase', 10000, 'buy:1', 'Purchase');
    });
    app(ProviderSettings::class)->save('whatsapp', 'meta', ['access_token' => 'system-token', 'app_secret' => 'app-secret'],
        ['phone_number_id' => 'wurk-pn', 'app_id' => 'meta-app', 'signup_config_id' => 'cfg-9', 'verify_token' => 'vt'], true, null);
    WhatsAppConnection::query()->create(['tenant_id' => $this->glow->id, 'waba_id' => 'waba-1', 'phone_number_id' => 'pn-glow', 'token' => 'glow-token', 'status' => 'connected']);
    $this->webhook = function (array $value, string $secret = 'app-secret') {
        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-1', 'changes' => [['field' => 'messages', 'value' => $value]]]]]);

        return $this->call('POST', $this->app('/webhooks/messaging/meta'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret)], $body);
    };
    $this->inbound = fn (string $id, array $message, string $from = '27825550101', string $pn = 'pn-glow') => ($this->webhook)([
        'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '27825550000', 'phone_number_id' => $pn],
        'contacts' => [['profile' => ['name' => 'Zodwa'], 'wa_id' => $from]],
        'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->getTimestamp()] + $message],
    ]);
    $this->chats = $this->app('/glow-salon/website/chats');
});

it('brings a customer’s WhatsApp messages into Chats, once each, with a contact', function () {
    ($this->inbound)('wamid.1', ['type' => 'text', 'text' => ['body' => 'Hi, can I book braids on Saturday?']])->assertOk();
    ($this->inbound)('wamid.1', ['type' => 'text', 'text' => ['body' => 'Hi, can I book braids on Saturday?']])->assertOk(); // Meta repeats itself
    ($this->inbound)('wamid.2', ['type' => 'location', 'location' => ['latitude' => -26.2, 'longitude' => 28.0, 'name' => 'Maponya Mall']]);

    ($this->in)(function () {
        $c = ReceptionistConversation::query()->sole();
        expect($c->only(['channel', 'status', 'name', 'phone']))->toBe(['channel' => 'whatsapp', 'status' => 'waiting', 'name' => 'Zodwa', 'phone' => '+27825550101'])
            ->and($c->contact_id)->not->toBeNull()->and($c->last_inbound_at)->not->toBeNull()
            ->and(ReceptionistMessage::query()->orderBy('position')->pluck('content')->pluck('content')->all())->toBe(['Hi, can I book braids on Saturday?', 'Maponya Mall']);
    });
    $this->actingAs($this->owner)->get($this->chats.'?show=whatsapp')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Website::Chats')
        ->where('whatsapp', true)->where('reply_credits', '5')->where('conversations.0.channel', 'whatsapp')->where('conversations.0.window_open', true)
        ->where('conversations.0.lines.0.text', 'Hi, can I book braids on Saturday?'));
    // Not mixed into the website assistant's tab.
    $this->actingAs($this->owner)->get($this->chats.'?show=ai')->assertInertia(fn (Assert $p) => $p->where('conversations', []));
});

it('refuses unsigned or wrongly signed webhooks, and keeps each business’s messages to itself', function () {
    ($this->webhook)(['metadata' => ['phone_number_id' => 'pn-glow'], 'messages' => [['from' => '27820000000', 'id' => 'x', 'type' => 'text', 'text' => ['body' => 'forged']]]], 'wrong')->assertForbidden();
    $other = $this->createTenant('other-salon');
    WhatsAppConnection::query()->create(['tenant_id' => $other->id, 'waba_id' => 'waba-2', 'phone_number_id' => 'pn-other', 'token' => 'other-token', 'status' => 'connected']);
    ($this->inbound)('wamid.other', ['type' => 'text', 'text' => ['body' => 'For the other salon']], '27820000001', 'pn-other');
    ($this->in)(fn () => expect(ReceptionistConversation::query()->count())->toBe(0));
    $this->ctx->run($other, fn () => expect(ReceptionistMessage::query()->sole()->content['content'])->toBe('For the other salon'));
});

it('replies from Chats inside the 24-hour window, with delivery ticks, and refuses after it', function () {
    Http::fake([WA_GRAPH.'/pn-glow/messages' => Http::response(['messages' => [['id' => 'wamid.out1']]])]);
    ($this->inbound)('wamid.1', ['type' => 'text', 'text' => ['body' => 'Are you open today?']]);
    $id = ($this->in)(fn () => ReceptionistConversation::query()->sole()->id);

    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/reply", ['text' => 'Yes, until 18:00!'])->assertSessionHas('status', 'Sent on WhatsApp.');
    Http::assertSent(fn ($r) => $r->url() === WA_GRAPH.'/pn-glow/messages' && $r->hasHeader('Authorization', 'Bearer glow-token')
        && $r['to'] === '27825550101' && $r['type'] === 'text' && $r['text']['body'] === 'Yes, until 18:00!');
    ($this->webhook)(['metadata' => ['phone_number_id' => 'pn-glow'], 'statuses' => [['id' => 'wamid.out1', 'status' => 'read', 'recipient_id' => '27825550101']]]);
    ($this->webhook)(['metadata' => ['phone_number_id' => 'pn-glow'], 'statuses' => [['id' => 'wamid.out1', 'status' => 'delivered', 'recipient_id' => '27825550101']]]); // late
    ($this->in)(function () {
        $out = ReceptionistMessage::query()->where('external_id', 'wamid.out1')->sole();
        expect($out->role)->toBe('person')->and($out->delivery)->toBe('read')
            ->and(ReceptionistConversation::query()->sole()->status)->toBe('person')
            ->and(app(CreditWallet::class)->spentThisMonth())->toBeGreaterThan(0);
    });

    $this->travel(25)->hours();
    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/reply", ['text' => 'Still there?'])
        ->assertSessionHasErrors(['text' => 'More than 24 hours have passed since Zodwa last wrote, so WhatsApp only allows an approved message. Send your follow-up, or call them.']);
    Http::assertSentCount(1);
});

it('follows up once with the approved template after the window closes, and the customer’s reply reopens it', function () {
    Http::fake([WA_GRAPH.'/pn-glow/messages' => Http::sequence()->push(['messages' => [['id' => 'wamid.fu']]])->push(['messages' => [['id' => 'wamid.out']]])]);
    ($this->inbound)('wamid.1', ['type' => 'text', 'text' => ['body' => 'How much for knotless braids?']]);
    $id = ($this->in)(fn () => ReceptionistConversation::query()->sole()->id);
    $this->travel(30)->hours();
    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/follow-up")->assertSessionHasErrors(['text' => 'Meta hasn’t approved your follow-up message yet. Check Settings → WhatsApp.']);
    $this->actingAs($this->owner)->get($this->chats.'?show=whatsapp')->assertInertia(fn (Assert $p) => $p->where('follow_up', false)->where('conversations.0.window_open', false));

    WhatsAppConnection::query()->update(['follow_up_status' => 'APPROVED']);
    $this->actingAs($this->owner)->get($this->chats.'?show=whatsapp')->assertInertia(fn (Assert $p) => $p->where('follow_up', true));
    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/follow-up")->assertSessionHas('status', 'Follow-up sent. When they reply, you can write freely again for 24 hours.');
    Http::assertSent(fn ($r) => $r['type'] === 'template' && $r['template']['name'] === 'wurk_follow_up'
        && $r['template']['components'][0]['parameters'] === [['type' => 'text', 'text' => 'Zodwa'], ['type' => 'text', 'text' => $this->glow->displayName()]]);
    ($this->in)(fn () => expect(ReceptionistMessage::query()->where('external_id', 'wamid.fu')->sole()->content['content'])
        ->toBe('Hi Zodwa, this is '.$this->glow->displayName().' following up on your message. Reply here and we’ll carry on the conversation.'));
    // One nudge per silence.
    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/follow-up")->assertSessionHasErrors(['text' => 'You’ve already followed up. Wait for Zodwa to reply.']);
    Http::assertSentCount(1);

    ($this->inbound)('wamid.2', ['type' => 'text', 'text' => ['body' => 'Yes please, Saturday?']]);
    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/follow-up")->assertSessionHasErrors(['text' => 'The 24-hour window is still open: write your reply instead.']);
    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/reply", ['text' => 'Saturday 10:00 works'])->assertSessionHas('status', 'Sent on WhatsApp.');
});

it('refunds the credits and explains when WhatsApp refuses a reply', function () {
    Http::fake([WA_GRAPH.'/pn-glow/messages' => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable']], 400)]);
    ($this->inbound)('wamid.1', ['type' => 'text', 'text' => ['body' => 'Hello']]);
    $id = ($this->in)(fn () => ReceptionistConversation::query()->sole()->id);
    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/reply", ['text' => 'Hi!'])->assertSessionHasErrors(['text' => 'This number can’t receive WhatsApp messages.']);
    ($this->in)(fn () => expect(app(CreditWallet::class)->spentThisMonth())->toBe(0));
});

it('fetches, scans and keeps the files customers send; sends photos and PDFs back', function () {
    Http::fake([
        WA_GRAPH.'/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'image/jpeg']),
        'https://lookaside.fbsbx.com/*' => Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        WA_GRAPH.'/media-2' => Http::response(['url' => 'https://evil.example.com/file', 'mime_type' => 'application/pdf']),
        WA_GRAPH.'/pn-glow/media' => Http::response(['id' => 'up-1']),
        WA_GRAPH.'/pn-glow/messages' => Http::response(['messages' => [['id' => 'wamid.out2']]]),
    ]);
    ($this->inbound)('wamid.p', ['type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg', 'caption' => 'This style please']]);
    ($this->inbound)('wamid.d', ['type' => 'document', 'document' => ['id' => 'media-2', 'mime_type' => 'application/pdf', 'filename' => 'quote.pdf']]);
    [$id, $photo] = ($this->in)(function () {
        $photo = ReceptionistMessage::query()->where('external_id', 'wamid.p')->sole();
        expect($photo->content['content'])->toBe('This style please')->and($photo->attachment['kind'])->toBe('image')
            ->and(ReceptionistMessage::query()->where('external_id', 'wamid.d')->sole()->attachment)->toBe(['blocked' => 'unavailable']); // not from Meta's servers: not fetched
        expect(Storage::disk('form-files')->get($photo->attachment['path']))->not->toBe('jpeg-bytes'); // encrypted at rest

        return [ReceptionistConversation::query()->sole()->id, $photo->id];
    });
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example.com'));
    $this->actingAs($this->owner)->get("{$this->chats}/ai/{$id}/files/{$photo}")->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertSeeText('jpeg-bytes');

    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/reply", ['text' => 'Here’s our price list', 'file' => UploadedFile::fake()->create('prices.pdf', 20, 'application/pdf')])
        ->assertSessionHas('status', 'Sent on WhatsApp.');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/pn-glow/messages') && $r['type'] === 'document' && $r['document']['id'] === 'up-1' && $r['document']['filename'] === 'prices.pdf');
    $this->actingAs($this->owner)->post("{$this->chats}/ai/{$id}/reply", ['file' => UploadedFile::fake()->create('virus.exe', 5, 'application/x-msdownload')])
        ->assertSessionHasErrors(['text' => 'Send a JPEG or PNG photo, or a PDF.']);

    $this->scanner->verdict = VirusScanner::INFECTED;
    ($this->inbound)('wamid.bad', ['type' => 'image', 'image' => ['id' => 'media-1', 'mime_type' => 'image/jpeg']]);
    ($this->in)(fn () => expect(ReceptionistMessage::query()->where('external_id', 'wamid.bad')->sole()->attachment)->toBe(['blocked' => 'infected']));
});

it('stops marketing messages when a customer replies STOP', function () {
    Http::fake([WA_GRAPH.'/pn-glow/messages' => Http::response(['messages' => [['id' => 'wamid.ack']]])]);
    ($this->inbound)('wamid.s', ['type' => 'text', 'text' => ['body' => 'STOP']]);
    ($this->in)(function () {
        $contact = (string) ReceptionistConversation::query()->sole()->contact_id;
        expect(app(Audiences::class)->hasConsent($contact))->toBeFalse();
    });
    Http::assertSent(fn ($r) => str_contains((string) ($r['text']['body'] ?? ''), 'You won’t get marketing messages from us any more.'));
});
