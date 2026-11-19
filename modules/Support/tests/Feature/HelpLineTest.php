<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Drivers\SimulatedSpeechDriver;
use Wurk\Ai\Models\AssistantQuestion;
use Wurk\Ai\Support\AiSettings;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Models\HelpCategory;
use Wurk\Messaging\Contracts\WurkWhatsApp;
use Wurk\Messaging\Events\WurkWhatsAppReceived;
use Wurk\Support\Jobs\AnswerHelpLine;
use Wurk\Support\Models\SupportTicket;
use Wurk\Support\Support\HelpLine;
use Wurk\Support\Support\Tickets;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 25.6: the WhatsApp help line. Owners message WURK's number from their confirmed mobile; Ask WURK answers from
 * the help centre in their language (typed or a voice note), never with figures or changes; it counts toward the
 * month's free questions; PERSON hands over to support with the conversation, and support's reply comes back on WhatsApp.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    SimulatedDriver::$answers = [];
    SimulatedDriver::$systems = [];
    SimulatedSpeechDriver::$script = [];
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->owner->forceFill(['phone_verified_at' => now(), 'locale' => 'zu'])->save();
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Thandi', 'owner'));
    $cat = HelpCategory::query()->create(['name' => 'Bookings', 'slug' => 'bookings', 'position' => 1]);
    HelpArticle::query()->create(['category_id' => $cat->id, 'title' => 'Add a service', 'slug' => 'add-a-service', 'summary' => 'Add what customers can book.',
        'body' => 'Go to **Bookings → Services → Add service**.', 'status' => 'published', 'screens' => []]);
    $this->sent = new class implements WurkWhatsApp
    {
        /** @var list<array{string, string}> */
        public array $texts = [];

        /** @var list<array{string, string, list<string>}> */
        public array $templates = [];

        public bool $windowOpen = true;

        public function available(): bool
        {
            return true;
        }

        public function sendText(string $to, string $text): bool
        {
            if (! $this->windowOpen) {
                return false;
            }
            $this->texts[] = [$to, $text];

            return true;
        }

        public function sendTemplate(string $to, string $template, array $parameters, string $language = 'en'): bool
        {
            $this->templates[] = [$to, $template, $parameters];

            return true;
        }

        public function media(string $mediaId): ?array
        {
            return $mediaId === '1234' ? ['contents' => 'ogg-bytes', 'mime' => 'audio/ogg; codecs=opus'] : null;
        }
    };
    $this->app->instance(WurkWhatsApp::class, $this->sent);
    $this->ask = fn (string $text, ?string $media = null) => app(HelpLine::class)->handle('27831000000', $text, $media, $media === null ? null : 'audio/ogg');
    // The question in English search words first (the help centre is in English), then Ask WURK's answer.
    $this->reply = fn (string $answer) => array_push(SimulatedDriver::$answers, 'add a service',
        (string) json_encode(['answer' => $answer, 'articles' => ['add-a-service'], 'tool' => null, 'unanswered' => false]));
});

it('answers an owner in isiZulu from the help centre, with the article, and counts it as an Ask WURK question', function () {
    ($this->reply)('Iya ku-Bookings → Services → Add service, bese ufaka igama nentengo.');
    ($this->ask)('Ngingayengeza kanjani isevisi?');

    expect($this->sent->texts)->toHaveCount(1)->and($this->sent->texts[0][0])->toBe('27831000000')
        ->and($this->sent->texts[0][1])->toStartWith('Iya ku-Bookings → Services → Add service')
        ->and($this->sent->texts[0][1])->toContain('https://'.config('wurk.domains.help').'/a/add-a-service')
        ->and($this->sent->texts[0][1])->toContain('PERSON');
    expect(SimulatedDriver::$systems[1])->toContain('Answer in plain, friendly isiZulu')
        ->not->toContain('Tools:'); // the help centre only: no figures, no changes
    $q = app(TenantContext::class)->run($this->glow, fn () => AssistantQuestion::query()->sole());
    expect($q->only(['user_id', 'channel', 'status', 'free']))->toBe(['user_id' => $this->owner->id, 'channel' => 'whatsapp', 'status' => 'answered', 'free' => true]);
});

it('listens to voice notes, and quotes credits once the month’s free questions are used, asking for YES first', function () {
    SimulatedSpeechDriver::$script = [['text' => 'Ngingayengeza kanjani isevisi?', 'language' => 'zu', 'confidence' => 0.92]];
    ($this->reply)('Iya ku-Bookings → Services.');
    ($this->ask)('', '1234');
    expect($this->sent->texts[0][1])->toStartWith('Iya ku-Bookings → Services.');
    expect(app(TenantContext::class)->run($this->glow, fn () => AssistantQuestion::query()->value('question')))->toBe('Ngingayengeza kanjani isevisi?');

    ($this->ask)('', '9999'); // a voice note that can't be fetched
    expect(end($this->sent->texts)[1])->toContain('couldn’t listen to that voice note');

    $s = app(AiSettings::class)->all();
    app(AiSettings::class)->save([...$s, 'assistant' => [...$s['assistant'], 'free_questions' => 1]], null);
    app(TenantContext::class)->run($this->glow, fn () => app(CreditWallet::class)->grant('purchase', 5000, 'buy:1', 'Purchase'));
    ($this->ask)('Ngingayisusa kanjani isevisi?');
    expect(end($this->sent->texts)[1])->toContain('Reply YES')->toContain('uses 1 credit');
    ($this->reply)('Iya ku-Services, ucindezele Delete.');
    ($this->ask)('Yebo');
    expect(end($this->sent->texts)[1])->toStartWith('Iya ku-Services, ucindezele Delete.');
    expect(app(TenantContext::class)->run($this->glow, fn () => AssistantQuestion::query()->where('question', 'Ngingayisusa kanjani isevisi?')->value('charged_milli')))->toBe(1000);
});

it('hands over to a person with the conversation, and support’s reply comes back on WhatsApp — a template once the window has closed', function () {
    ($this->reply)('Iya ku-Bookings → Services.');
    ($this->ask)('Ngingayengeza kanjani isevisi?');
    ($this->ask)('person');

    $ticket = SupportTicket::query()->sole();
    expect($ticket->only(['tenant_id', 'opened_by', 'subject', 'page']))->toBe(['tenant_id' => $this->glow->id, 'opened_by' => $this->owner->id,
        'subject' => 'WhatsApp help line', 'page' => Tickets::HELP_LINE]);
    expect($ticket->messages()->sole()->body)->toContain('Q: Ngingayengeza kanjani isevisi?')->toContain('A: Iya ku-Bookings → Services.');
    expect(end($this->sent->texts)[1])->toContain($ticket->reference());

    $staff = $this->staffWithRole('support', 'help@wurk.co.za');
    app(Tickets::class)->replyFromStaff($ticket, $staff->id, 'Hi Thandi — open Bookings → Services and tap Add service.', false);
    expect(end($this->sent->texts)[1])->toContain('Hi Thandi — open Bookings → Services');

    $this->sent->windowOpen = false;
    app(Tickets::class)->replyFromStaff($ticket->refresh(), $staff->id, 'Did that work?', true);
    expect($this->sent->templates)->toBe([['27831000000', 'wurk_help_reply', [$ticket->reference(), 'Did that work?']]]);
});

it('tells a number it doesn’t know how to reach the help line — once a day — and leaves STOP and summary replies alone', function () {
    app(HelpLine::class)->handle('27820000000', 'Hello?', null, null);
    app(HelpLine::class)->handle('27820000000', 'Hello??', null, null);
    expect($this->sent->texts)->toHaveCount(1)->and($this->sent->texts[0][1])->toContain('mobile number on your WURK account');

    Queue::fake();
    event(new WurkWhatsAppReceived('27831000000', 'STOP'));
    event(new WurkWhatsAppReceived('27831000000', '1', answered: true));
    event(new WurkWhatsAppReceived('27831000000', 'How do I add a service?'));
    Queue::assertPushed(AnswerHelpLine::class, 1);
});
