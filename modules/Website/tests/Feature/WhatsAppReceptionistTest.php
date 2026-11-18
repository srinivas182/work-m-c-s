<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Jobs\AnswerOnWhatsApp;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Receptionist;
use Wurk\Website\Support\WhatsAppReceptionist;

/** Bookings' free times and booking, as the receptionist sees them (the shapes WhatsApp turns into buttons and links). */
final class WaFreeTimes implements ReceptionistTool
{
    /** @var list<string> */
    public static array $times = ['14:00', '15:30'];

    public function name(): string
    {
        return 'free_times';
    }

    public function description(): string
    {
        return 'Free times';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['date' => ['type' => 'string']]];
    }

    public function capability(): string
    {
        return 'book';
    }

    public function entitlement(): ?string
    {
        return null;
    }

    public function run(array $arguments, array $context): array
    {
        return ['service' => 'Box braids', 'day' => 'Saturday 21 November 2026', 'price' => 'R850.00',
            'times' => array_map(fn (string $t) => ['starts_at' => '2026-11-21T'.$t.':00+02:00', 'time' => $t], self::$times)];
    }
}

final class WaBook implements ReceptionistTool
{
    public function name(): string
    {
        return 'book_appointment';
    }

    public function description(): string
    {
        return 'Book';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['starts_at' => ['type' => 'string']]];
    }

    public function capability(): string
    {
        return 'book';
    }

    public function entitlement(): ?string
    {
        return null;
    }

    public function run(array $arguments, array $context): array
    {
        return ['booked' => true, 'card' => ['type' => 'appointment', 'service' => 'Box braids', 'when' => 'Saturday 21 November, 14:00', 'price' => 'R850.00',
            'deposit' => 'R200.00', 'action' => ['label' => 'Pay the deposit', 'url' => 'https://glow-salon.wurk.test/pay/abc']]];
    }
}

/*
 * Sprint 21.3: the AI receptionist on the business's WhatsApp number, with WhatsApp's buttons, lists and link buttons.
 */
uses(InteractsWithIdentity::class, InteractsWithShards::class);

const WA_API = 'https://graph.facebook.com/v23.0';

beforeEach(function () {
    Mail::fake();
    SimulatedDriver::$failNext = 0;
    SimulatedDriver::$script = [];
    SimulatedDriver::$seen = [];
    WaFreeTimes::$times = ['14:00', '15:30'];
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
    app(ReceptionistTools::class)->register(WaFreeTimes::class);
    app(ReceptionistTools::class)->register(WaBook::class);
    app(ProviderSettings::class)->save('whatsapp', 'meta', ['access_token' => 'system-token', 'app_secret' => 'app-secret'],
        ['phone_number_id' => 'wurk-pn', 'app_id' => 'meta-app', 'signup_config_id' => 'cfg-9'], true, null);
    WhatsAppConnection::query()->create(['tenant_id' => $this->glow->id, 'waba_id' => 'waba-1', 'phone_number_id' => 'pn-glow', 'token' => 'glow-token', 'status' => 'connected']);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Owner', 'owner');
        Site::query()->create(['branding' => ['primary' => '#6D4AFF'], 'menus' => [], 'settings' => ['chat' => ['enabled' => true], 'enquiry_email' => 'owner@glow.co.za']]);
        app(Receptionist::class)->save(['whatsapp' => true]);
        app(CreditWallet::class)->grant('purchase', 50000, 'buy:1', 'Purchase');
    });
    $this->sent = 0;
    Http::fake([WA_API.'/pn-glow/messages' => function () {
        return Http::response(['messages' => [['id' => 'wamid.out'.(++$this->sent)]]]);
    }]);
    $this->inbound = function (string $id, array $message) {
        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-1', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => 'pn-glow'], 'contacts' => [['profile' => ['name' => 'Zodwa Mkhize'], 'wa_id' => '27825550101']],
            'messages' => [['from' => '27825550101', 'id' => $id, 'timestamp' => (string) now()->getTimestamp()] + $message]]]]]]]);

        return $this->call('POST', $this->app('/webhooks/messaging/meta'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret')], $body)->assertOk();
    };
    $this->text = fn (string $id, string $text) => ($this->inbound)($id, ['type' => 'text', 'text' => ['body' => $text]]);
    $this->sentTo = fn () => collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn ($r) => str_ends_with($r->url(), '/pn-glow/messages'))->values();
});

it('answers on WhatsApp with the times as buttons, books from a tap, and sends the deposit link as a button', function () {
    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['date' => '2026-11-21']], ['text' => 'Saturday has two free times for box braids at R850. Which suits you?']];
    ($this->text)('wamid.1', 'Can I get box braids on Saturday?');

    $first = ($this->sentTo)()->sole();
    expect($first['type'])->toBe('interactive')->and($first['interactive']['type'])->toBe('button')
        ->and($first['interactive']['body']['text'])->toBe('Saturday has two free times for box braids at R850. Which suits you?')
        ->and($first['interactive']['action']['buttons'])->toBe([['type' => 'reply', 'reply' => ['id' => 'choice:0', 'title' => '14:00']], ['type' => 'reply', 'reply' => ['id' => 'choice:1', 'title' => '15:30']]])
        ->and(SimulatedDriver::$seen[0]['system'])->toContain('Glow Salon\'s WhatsApp')->toContain('buttons or a list to tap');

    // The customer taps 14:00.
    SimulatedDriver::$script = [['tool' => 'book_appointment', 'arguments' => ['starts_at' => '2026-11-21T14:00:00+02:00']], ['text' => 'You’re booked for box braids on Saturday at 14:00.']];
    ($this->inbound)('wamid.2', ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'choice:0', 'title' => '14:00']]]);
    expect(collect(end(SimulatedDriver::$seen)['items'])->where('role', 'user')->pluck('content')->all())->toBe(['Can I get box braids on Saturday?', '14:00']);
    [, $booked, $link] = ($this->sentTo)()->all();
    expect($booked['type'])->toBe('text')->and($booked['text']['body'])->toBe('You’re booked for box braids on Saturday at 14:00.')
        ->and($link['interactive']['type'])->toBe('cta_url')
        ->and($link['interactive']['action']['parameters'])->toBe(['display_text' => 'Pay the deposit', 'url' => 'https://glow-salon.wurk.test/pay/abc'])
        ->and($link['interactive']['body']['text'])->toContain('Saturday 21 November, 14:00')->toContain('R200.00');

    ($this->in)(function () {
        $c = ReceptionistConversation::query()->sole();
        expect($c->only(['channel', 'status', 'outcome']))->toBe(['channel' => 'whatsapp', 'status' => 'ai', 'outcome' => 'booked'])
            ->and(ReceptionistMessage::query()->where('external_id', 'wamid.out2')->sole()->content['content'])->toBe('You’re booked for box braids on Saturday at 14:00.');
        // The link button isn't fed back to the AI as its own words.
        expect(ReceptionistMessage::query()->where('external_id', 'wamid.out3')->sole()->content['card'])->toBeTrue();
    });
    // Chats shows the conversation, with the assistant's ticks.
    $this->actingAs($this->owner)->get($this->app('/glow-salon/website/chats').'?show=whatsapp')->assertInertia(fn (Assert $p) => $p
        ->where('conversations.0.status', 'ai')->where('conversations.0.lines.1.from', 'assistant')->where('conversations.0.lines.1.delivery', 'sent'));
});

it('uses a list for more than three choices (up to ten), and reads a tapped row with its description', function () {
    WaFreeTimes::$times = array_map(fn (int $h) => sprintf('%02d:00', $h), range(8, 19)); // 12 times
    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['date' => '2026-11-21']], ['text' => 'Pick a time on Saturday:']];
    ($this->text)('wamid.1', 'Saturday?');
    $list = ($this->sentTo)()->sole()['interactive'];
    expect($list['type'])->toBe('list')->and($list['action']['button'])->toBe('Choose')
        ->and($list['action']['sections'][0]['rows'])->toHaveCount(10)
        ->and($list['action']['sections'][0]['rows'][0])->toBe(['id' => 'choice:0', 'title' => '08:00', 'description' => 'Saturday 21 November 2026']);

    SimulatedDriver::$script = [['text' => 'Great.']];
    ($this->inbound)('wamid.2', ['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => 'choice:2', 'title' => '10:00', 'description' => 'Saturday 21 November 2026']]]);
    expect(collect(end(SimulatedDriver::$seen)['items'])->where('role', 'user')->last()['content'])->toBe('10:00 — Saturday 21 November 2026');
});

it('waits for the customer to finish typing, then answers once with all their messages', function () {
    Queue::fake([AnswerOnWhatsApp::class]);
    ($this->text)('wamid.1', 'Hi');
    ($this->text)('wamid.2', 'Do you do braids?');
    $jobs = Queue::pushed(AnswerOnWhatsApp::class);
    expect($jobs)->toHaveCount(2)->and($jobs->first()->delay)->not->toBeNull();

    SimulatedDriver::$script = [['text' => 'Hi Zodwa! Yes, we do.']];
    $jobs->each(fn (AnswerOnWhatsApp $job) => app()->call([$job, 'handle']));
    expect(SimulatedDriver::$seen)->toHaveCount(1)
        ->and(collect(SimulatedDriver::$seen[0]['items'])->pluck('content')->all())->toBe(['Hi', 'Do you do braids?'])
        ->and(($this->sentTo)()->sole()['text']['body'])->toBe('Hi Zodwa! Yes, we do.');
    app()->call([$jobs->last(), 'handle']); // a retry doesn't answer twice
    expect(SimulatedDriver::$seen)->toHaveCount(1);
});

it('stays quiet while the team has the chat, and answers again when handed back', function () {
    SimulatedDriver::$script = [['text' => 'Hello!']];
    ($this->text)('wamid.1', 'Hi');
    $id = ($this->in)(fn () => ReceptionistConversation::query()->sole()->id);
    $chats = $this->app('/glow-salon/website/chats');
    $this->actingAs($this->owner)->put("{$chats}/ai/{$id}", ['action' => 'take_over']);
    ($this->text)('wamid.2', 'Can I speak to Lindiwe?');
    expect(SimulatedDriver::$seen)->toHaveCount(1)->and(($this->sentTo)())->toHaveCount(1);

    $this->actingAs($this->owner)->put("{$chats}/ai/{$id}", ['action' => 'hand_back']);
    SimulatedDriver::$script = [['text' => 'Sure, how can I help?']];
    ($this->text)('wamid.3', 'Thanks, one more question');
    expect(SimulatedDriver::$seen)->toHaveCount(2)->and(($this->sentTo)())->toHaveCount(2);
});

it('passes the chat to the team — never silence — when over its limit, out of credits or switched off', function () {
    ($this->in)(fn () => app(Receptionist::class)->save(['monthly_cap' => 0]));
    ($this->text)('wamid.1', 'Hi there');
    ($this->text)('wamid.2', 'Hello?');
    expect(($this->sentTo)()->sole()['text']['body'])->toBe(WhatsAppReceptionist::PASSED_ON) // told once
        ->and(($this->in)(fn () => ReceptionistConversation::query()->sole()->status))->toBe('waiting')
        ->and(SimulatedDriver::$seen)->toBe([]);

    // Switched off for WhatsApp: new chats wait for the team from the start.
    ($this->in)(function () {
        ReceptionistConversation::query()->update(['status' => 'closed']);
        app(Receptionist::class)->save(['whatsapp' => false, 'monthly_cap' => 300]);
    });
    ($this->text)('wamid.3', 'Hi again');
    expect(($this->in)(fn () => ReceptionistConversation::query()->where('status', '!=', 'closed')->sole()->status))->toBe('waiting')
        ->and(($this->sentTo)())->toHaveCount(1);
});

it('sends the owner’s away message once a day when closed, then still helps', function () {
    ($this->in)(function () {
        $site = Site::query()->sole();
        $site->update(['settings' => [...(array) $site->settings, 'checklist' => ['hours' => true]]]);
        app(Receptionist::class)->save(['away' => 'We’re closed — back at 8 tomorrow! I can book you in now.']);
        $page = Page::query()->create(['title' => 'Home', 'slug' => 'home', 'is_home' => true, 'draft' => '{"blocks":[]}']);
        $hours = [...array_fill(0, 5, ['opens' => '08:00', 'closes' => '17:00']), ['opens' => '08:00', 'closes' => '13:00'], ['opens' => '', 'closes' => '']];
        $v = PageVersion::query()->create(['page_id' => $page->id, 'number' => 1, 'title' => 'Home', 'created_at' => now(),
            'content' => (string) json_encode(['blocks' => [['type' => 'OpeningHours', 'props' => ['hours' => $hours]]]])]);
        $page->update(['published_version_id' => $v->id]);
    });
    $this->travelTo(now()->setTimezone('Africa/Johannesburg')->next('Wednesday')->setTime(21, 0)); // after closing
    SimulatedDriver::$script = [['text' => 'Happy to book you in — which day?'], ['text' => 'Sure.']];
    ($this->text)('wamid.1', 'Hi, can I book?');
    ($this->text)('wamid.2', 'For Friday');
    $bodies = ($this->sentTo)()->map(fn ($r) => $r['text']['body'])->all();
    expect($bodies)->toBe(['We’re closed — back at 8 tomorrow! I can book you in now.', 'Happy to book you in — which day?', 'Sure.'])
        ->and(SimulatedDriver::$seen[0]['system'])->toContain('The business is closed right now');

    ($this->in)(function () {
        $wa = app(WhatsAppReceptionist::class);
        $sat = now()->setTimezone('Africa/Johannesburg')->next('Saturday');
        expect($wa->closedNow($sat->setTime(12, 0)->toImmutable()))->toBeFalse()
            ->and($wa->closedNow($sat->setTime(13, 30)->toImmutable()))->toBeTrue()
            ->and($wa->closedNow($sat->addDay()->setTime(10, 0)->toImmutable()))->toBeTrue(); // Sunday
    });
});

it('lets the owner switch it on for WhatsApp separately, only with a connected number', function () {
    $settings = ['enabled' => false, 'whatsapp' => true, 'may' => ['book'], 'tone' => 'friendly', 'greeting' => 'Hi!', 'languages' => ['en'],
        'person_hours' => '', 'monthly_cap' => 300, 'away' => 'Closed now — back soon.'];
    $chat = $this->app('/glow-salon/website/chat');
    $this->actingAs($this->owner)->put("{$chat}/receptionist", $settings)->assertSessionHas('status', 'AI assistant saved — it’s answering on WhatsApp.');
    ($this->in)(fn () => expect(app(Receptionist::class)->settings())->toMatchArray(['enabled' => false, 'whatsapp' => true, 'away' => 'Closed now — back soon.']));
    $this->actingAs($this->owner)->get($chat)->assertInertia(fn (Assert $p) => $p->where('receptionist.whatsapp_connected', true));

    WhatsAppConnection::query()->update(['status' => 'disconnected']);
    $this->actingAs($this->owner)->put("{$chat}/receptionist", $settings)->assertSessionHasErrors(['whatsapp' => 'Connect your WhatsApp number first (Settings → WhatsApp).']);
});
