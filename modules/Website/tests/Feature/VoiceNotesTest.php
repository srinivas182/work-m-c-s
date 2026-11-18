<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Drivers\SimulatedSpeechDriver;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Receptionist;
use Wurk\Website\Support\Uploads\VirusScanner;
use Wurk\Website\Support\VoiceNotes;

/*
 * Sprint 21.4: customers' WhatsApp voice notes are transcribed, shown in Chats with a player, and answered by the
 * assistant as if typed. The Graph API, the scanner and the speech provider are faked.
 */
uses(InteractsWithIdentity::class, InteractsWithShards::class);

const VN_API = 'https://graph.facebook.com/v23.0';

/** A minimal Ogg Opus file of the given length: an OpusHead page and a last page with the sample position. */
function oggOpus(float $seconds): string
{
    $page = fn (int $granule, string $packet) => 'OggS'."\0\0".pack('P', $granule).pack('V', 1).pack('V', 0).pack('V', 0).chr(1).chr(strlen($packet)).$packet;
    $head = 'OpusHead'.chr(1).chr(1).pack('v', 312).pack('V', 48000).pack('v', 0).chr(0);

    return $page(0, $head).$page(0, str_repeat("\x01", 40)).$page((int) round($seconds * 48000) + 312, str_repeat("\x02", 40));
}

beforeEach(function () {
    Mail::fake();
    Storage::fake('form-files');
    app()->instance(VirusScanner::class, new class implements VirusScanner
    {
        public function scan(string $contents): ?string
        {
            return VirusScanner::CLEAN;
        }
    });
    SimulatedDriver::$script = [];
    SimulatedDriver::$seen = [];
    SimulatedSpeechDriver::$script = [];
    SimulatedSpeechDriver::$heard = [];
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
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
    app(ProviderSettings::class)->save('whatsapp', 'meta', ['access_token' => 'system-token', 'app_secret' => 'app-secret'],
        ['phone_number_id' => 'wurk-pn', 'app_id' => 'meta-app', 'signup_config_id' => 'cfg-9'], true, null);
    WhatsAppConnection::query()->create(['tenant_id' => $this->glow->id, 'waba_id' => 'waba-1', 'phone_number_id' => 'pn-glow', 'token' => 'glow-token', 'status' => 'connected']);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Owner', 'owner');
        Site::query()->create(['branding' => ['primary' => '#6D4AFF'], 'menus' => [], 'settings' => ['chat' => ['enabled' => true]]]);
        app(Receptionist::class)->save(['whatsapp' => true]);
        app(CreditWallet::class)->grant('purchase', 50000, 'buy:1', 'Purchase');
    });
    $this->audio = oggOpus(14.5);
    $this->sent = 0;
    Http::fake([
        VN_API.'/media-*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'audio/ogg; codecs=opus']),
        'https://lookaside.fbsbx.com/*' => fn () => Http::response($this->audio, 200, ['Content-Type' => 'audio/ogg']),
        VN_API.'/pn-glow/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.(++$this->sent)]]]),
    ]);
    $this->voice = function (string $id) {
        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-1', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'pn-glow'], 'contacts' => [['profile' => ['name' => 'Zodwa'], 'wa_id' => '27825550101']],
            'messages' => [['from' => '27825550101', 'id' => $id, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'audio',
                'audio' => ['id' => 'media-'.$id, 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]]]]]]]]);

        return $this->call('POST', $this->app('/webhooks/messaging/meta'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret')], $body)->assertOk();
    };
    $this->replies = fn () => collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_ends_with($r->url(), '/pn-glow/messages'))->values();
});

it('reads a voice note’s length from the recording', function () {
    expect(VoiceNotes::seconds(oggOpus(14.5), 'audio/ogg'))->toBe(14.5)
        ->and(VoiceNotes::seconds('ID3 not ogg', 'audio/mpeg'))->toBeNull()
        ->and(VoiceNotes::tooLong(oggOpus(121), 121.0))->toBeTrue()
        ->and(VoiceNotes::tooLong(str_repeat('x', 100), null))->toBeFalse();
});

it('transcribes a voice note, charges for it, shows it in Chats with a player, and the assistant answers the words', function () {
    SimulatedSpeechDriver::$script = [['text' => 'Ngicela ukubhuka ama-braids ngoMgqibelo', 'language' => 'zu', 'confidence' => 0.91]];
    SimulatedDriver::$script = [['text' => 'Yebo! Ngingakubhukela ngoMgqibelo. Iyiphi isikhathi esikufanelayo?']];
    ($this->voice)('wamid.v1');

    expect(SimulatedSpeechDriver::$heard)->toHaveCount(1)->and(SimulatedSpeechDriver::$heard[0]['mime'])->toBe('audio/ogg; codecs=opus')
        ->and(collect(SimulatedDriver::$seen[0]['items'])->where('role', 'user')->pluck('content')->all())->toBe(['Ngicela ukubhuka ama-braids ngoMgqibelo'])
        ->and(($this->replies)()->sole()['text']['body'])->toBe('Yebo! Ngingakubhukela ngoMgqibelo. Iyiphi isikhathi esikufanelayo?');
    [$id, $voice] = ($this->in)(function () {
        $m = ReceptionistMessage::query()->where('external_id', 'wamid.v1')->sole();
        expect($m->content['voice'])->toBe(['language' => 'zu', 'confidence' => 0.91, 'unclear' => false])
            ->and($m->attachment['seconds'])->toBe(15)->and($m->attachment['kind'])->toBe('audio')
            ->and(collect(app(CreditWallet::class)->usageThisMonth())->toJson())->toContain('ai.transcribe');

        return [$m->conversation_id, $m->id];
    });
    $chats = $this->app('/glow-salon/website/chats');
    $this->actingAs($this->owner)->get($chats.'?show=whatsapp')->assertInertia(fn (Assert $p) => $p
        ->where('conversations.0.lines.0.text', 'Ngicela ukubhuka ama-braids ngoMgqibelo')
        ->where('conversations.0.lines.0.voice', ['seconds' => 15, 'unclear' => false, 'language' => 'isiZulu', 'transcribed' => true])
        ->where('conversations.0.lines.0.file.kind', 'audio'));
    $this->actingAs($this->owner)->get("{$chats}/ai/{$id}/files/{$voice}")->assertOk()->assertHeader('Content-Type', 'audio/ogg; codecs=opus');
});

it('asks the customer to confirm when unsure, and to type when the note is too long or can’t be heard', function () {
    SimulatedSpeechDriver::$script = [['text' => 'Ek wil Saterdag kom', 'language' => 'af', 'confidence' => 0.41]];
    SimulatedDriver::$script = [['text' => 'Ek het verstaan: jy wil Saterdag kom. Is dit reg?']];
    ($this->voice)('wamid.v1');
    expect(collect(SimulatedDriver::$seen[0]['items'])->where('role', 'user')->last()['content'])->toBe('[Voice note, unclear] Ek wil Saterdag kom')
        ->and(SimulatedDriver::$seen[0]['system'])->toContain('ask them to confirm or type it');

    $this->audio = oggOpus(150); // 2½ minutes: not transcribed
    SimulatedDriver::$script = [['text' => 'Sorry, that voice note is too long for me — could you type it, or send a shorter one?']];
    ($this->voice)('wamid.v2');
    expect(SimulatedSpeechDriver::$heard)->toHaveCount(1)
        ->and(collect(end(SimulatedDriver::$seen)['items'])->where('role', 'user')->last()['content'])->toBe('[Voice note, 3 minutes — too long to listen to]');

    $this->audio = oggOpus(10);
    SimulatedSpeechDriver::$script = ['fail'];
    SimulatedDriver::$script = [['text' => 'I couldn’t listen to that — could you type it?']];
    $before = ($this->in)(fn () => app(CreditWallet::class)->spentThisMonth());
    ($this->voice)('wamid.v3');
    expect(collect(end(SimulatedDriver::$seen)['items'])->where('role', 'user')->last()['content'])->toBe('[Voice note]')
        ->and(($this->replies)())->toHaveCount(3);
    // The failed transcription was refunded; only the assistant's reply and its WhatsApp message were charged.
    ($this->in)(fn () => expect(app(CreditWallet::class)->spentThisMonth() - $before)->toBe(app(CreditWallet::class)->rateFor('ai.receptionist') + app(CreditWallet::class)->rateFor('whatsapp')));
});

it('transcribes for the team when the assistant is off, and deletes the audio after 30 days, keeping the words', function () {
    ($this->in)(fn () => app(Receptionist::class)->save(['whatsapp' => false]));
    ($this->voice)('wamid.v1');
    expect(SimulatedDriver::$seen)->toBe([])->and(($this->replies)())->toHaveCount(0);
    $path = ($this->in)(fn () => ReceptionistMessage::query()->where('external_id', 'wamid.v1')->sole()->attachment['path']);
    Storage::disk('form-files')->assertExists($path);

    $this->travel(31)->days();
    $this->artisan('website:sweep-conversations')->expectsOutput("Conversations deleted: 0. Voice notes' audio deleted: 1.")->assertSuccessful();
    Storage::disk('form-files')->assertMissing($path);
    ($this->in)(function () {
        $m = ReceptionistMessage::query()->where('external_id', 'wamid.v1')->sole();
        expect($m->content['content'])->toBe('Hello, can I book for Saturday?')->and($m->attachment)->toMatchArray(['path' => null, 'expired' => true]);
    });
});
