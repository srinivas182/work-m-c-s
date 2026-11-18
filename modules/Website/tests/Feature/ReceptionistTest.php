<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Mail\ChatHandoffMail;
use Wurk\Website\Models\ChatAnswer;
use Wurk\Website\Models\ChatHandoff;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\ChatBot;
use Wurk\Website\Support\Receptionist;

uses(InteractsWithShards::class);

/** A stand-in for Bookings' tools (19.2b): free times, and booking. */
final class FakeFreeTimes implements ReceptionistTool
{
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
        if (($arguments['date'] ?? '') === 'sunday') {
            throw new ReceptionistRefused('Closed on Sundays.');
        }

        return ['service' => 'Box braids', 'price_cents' => 85000, 'long_braids' => 'R1 200.00', 'times' => ['14:00', '15:30']];
    }
}

final class FakeCancel implements ReceptionistTool
{
    public function name(): string
    {
        return 'cancel_booking';
    }

    public function description(): string
    {
        return 'Cancel';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
    }

    public function capability(): string
    {
        return 'change';
    }

    public function entitlement(): ?string
    {
        return null;
    }

    public function run(array $arguments, array $context): array
    {
        return ['cancelled' => true];
    }
}

/** Sprint 19.2: the AI receptionist on a business's website chat. */
beforeEach(function () {
    Mail::fake();
    SimulatedDriver::$failNext = 0;
    SimulatedDriver::$script = [];
    SimulatedDriver::$seen = [];
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->base = 'http://glow-salon.'.config('wurk.domains.root');
    app(ReceptionistTools::class)->register(FakeFreeTimes::class);
    app(ReceptionistTools::class)->register(FakeCancel::class);
    $this->ctx->run($this->glow, function () {
        Site::query()->create(['branding' => ['primary' => '#6D4AFF'], 'menus' => [], 'settings' => ['chat' => ['enabled' => true], 'enquiry_email' => 'owner@glow.co.za']]);
        ChatAnswer::query()->create(['kind' => 'keyword', 'keywords' => 'parking, park', 'answer' => 'Free parking behind the salon.', 'position' => 0]);
        app(Receptionist::class)->save(['enabled' => true]);
        app(CreditWallet::class)->grant('purchase', 5000, 'buy:1', 'Purchase');
    });
    $this->say = fn (string $text, ?string $conversation = null, string $visitor = 'visitor-key-0123456789') => $this->postJson($this->base.'/chat/message',
        ['visitor' => $visitor, 'message' => $text, 'conversation' => $conversation])->assertOk()->json();
    $this->in = fn (callable $f) => $this->ctx->run($this->glow, $f);
});

it('answers from the owner’s own answers, keeps the conversation, and charges a reply', function () {
    SimulatedDriver::$script = [['tool' => 'owner_answers'], ['text' => 'Yes — there’s free parking behind the salon.']];
    $r = ($this->say)('Is there parking?');

    expect($r['mode'])->toBe('ai')->and($r['reply'])->toBe('Yes — there’s free parking behind the salon.');
    ($this->in)(function () use ($r) {
        $c = ReceptionistConversation::query()->findOrFail($r['conversation']);
        expect($c->replies)->toBe(1)->and($c->credits_milli)->toBe(200)->and($c->outcome)->toBe('answered')->and($c->keep)->toBeFalse()
            ->and(ReceptionistMessage::query()->where('conversation_id', $c->id)->orderBy('position')->pluck('role')->all())->toBe(['user', 'assistant', 'tool', 'assistant'])
            ->and(app(CreditWallet::class)->spentThisMonth())->toBe(200);
    });
    // The owner's answer reached the AI, and it was told it's an AI that never invents.
    expect(json_encode(SimulatedDriver::$seen[1]['items']))->toContain('Free parking behind the salon.')
        ->and(SimulatedDriver::$seen[0]['system'])->toContain('You are an AI, not a person')->toContain('Never guess');
});

it('offers only the tools the owner allowed, and carries the conversation on', function () {
    SimulatedDriver::$script = [['text' => 'Hello!'], ['text' => 'Sure.']];
    $first = ($this->say)('Hi');
    expect(SimulatedDriver::$seen[0]['tools'])->toBe(['business_facts', 'owner_answers', 'hand_over', 'free_times']); // booking yes, cancelling no

    ($this->say)('Can I book?', $first['conversation']);
    expect(collect(SimulatedDriver::$seen[1]['items'])->pluck('content')->all())->toBe(['Hi', 'Hello!', 'Can I book?']);
    // Another browser can't continue someone else's conversation: it gets its own.
    $other = ($this->say)('Hi', $first['conversation'], 'someone-else-0123456789');
    expect($other['conversation'])->not->toBe($first['conversation']);

    ($this->in)(fn () => app(Receptionist::class)->save(['may' => ['book', 'change']]));
    ($this->say)('Cancel please', $first['conversation']);
    expect(end(SimulatedDriver::$seen)['tools'])->toContain('cancel_booking');
});

it('never passes on a time or price no tool gave — it says so and offers a person', function () {
    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['date' => 'saturday']], ['text' => 'Saturday at 14:00 for R850 works.']];
    expect(($this->say)('Box braids Saturday?')['reply'])->toBe('Saturday at 14:00 for R850 works.');

    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['date' => 'saturday']], ['text' => 'We have 16:45 on Saturday.']];
    expect(($this->say)('Anything later?')['reply'])->toContain('couldn’t check that properly');
    SimulatedDriver::$script = [['text' => 'Braids are R1200.']];
    expect(($this->say)('How much?')['reply'])->toContain('couldn’t check that properly');
    // Thousands written with a space, as WURK and South Africans write them (found by the 19.7 evaluation set).
    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['date' => 'saturday']], ['text' => 'That’s R850 — or R1 200 for long braids.']];
    expect(($this->say)('And long braids?')['reply'])->toBe('That’s R850 — or R1 200 for long braids.');
    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['date' => 'saturday']], ['text' => 'Long braids are R1 500.']];
    expect(($this->say)('Sure?')['reply'])->toContain('couldn’t check that properly');
    // A tool's refusal reaches the AI as an error, not a crash.
    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['date' => 'sunday']], ['text' => 'We’re closed on Sundays.']];
    expect(($this->say)('Sunday?')['reply'])->toBe('We’re closed on Sundays.')
        ->and(json_encode(end(SimulatedDriver::$seen)['items']))->toContain('Closed on Sundays.');
});

it('falls back to the rule-based chat when off, out of credits, over its cap, or when WURK AI is down — refunding a failed reply', function () {
    ($this->in)(fn () => app(Receptionist::class)->save(['enabled' => false]));
    expect(($this->say)('Hi')['mode'])->toBe('rules');

    ($this->in)(fn () => app(Receptionist::class)->save(['enabled' => true, 'monthly_cap' => 0]));
    expect(($this->say)('Hi')['mode'])->toBe('rules');

    ($this->in)(fn () => app(Receptionist::class)->save(['monthly_cap' => 300]));
    SimulatedDriver::$failNext = 1;
    $before = ($this->in)(fn () => app(CreditWallet::class)->spentThisMonth());
    expect(($this->say)('Hi')['mode'])->toBe('rules')
        ->and(($this->in)(fn () => app(CreditWallet::class)->spentThisMonth()))->toBe($before); // refunded

    ($this->in)(fn () => app(CreditWallet::class)->setLimits(0, [], null)); // the owner's spending limit reached
    expect(($this->say)('Hi')['mode'])->toBe('rules');
});

it('hands over to a person: stored with the contact, the owner emailed, and later messages passed on', function () {
    SimulatedDriver::$script = [
        ['tool' => 'hand_over', 'arguments' => ['name' => 'Thandi', 'phone' => '082 555 0101', 'message' => 'Wants a quote for a wedding party', 'agreed_to_be_contacted' => false]],
        ['tool' => 'hand_over', 'arguments' => ['name' => 'Thandi', 'phone' => '082 555 0101', 'message' => 'Wants a quote for a wedding party', 'agreed_to_be_contacted' => true]],
        ['text' => 'Done — someone will get back to you as soon as they can.'],
    ];
    $r = ($this->say)('I need a quote for 6 bridesmaids');
    expect($r['reply'])->toStartWith('Done')
        ->and(json_encode(SimulatedDriver::$seen[1]['items']))->toContain('Ask whether they agree to be contacted');
    ($this->in)(function () use ($r) {
        $h = ChatHandoff::query()->sole();
        expect($h->name)->toBe('Thandi')->and($h->contact_id)->not->toBeNull()->and($h->transcript[0])->toBe(['from' => 'visitor', 'text' => 'I need a quote for 6 bridesmaids'])
            ->and(ReceptionistConversation::query()->find($r['conversation']))->status->toBe('waiting')->outcome->toBe('handed_over')->keep->toBeTrue();
    });
    Mail::assertSent(ChatHandoffMail::class);
    $next = ($this->say)('Also my sister is coming', $r['conversation']);
    expect($next['mode'])->toBe('person')->and(SimulatedDriver::$seen)->toHaveCount(3); // the AI isn't asked again
});

it('tells the chat when the AI receptionist is on, and refuses bad requests', function () {
    $c = $this->getJson($this->base.'/chat')->assertOk()->json('ai');
    expect($c['greeting'])->toContain('AI assistant')->and($c['notice'])->toContain('kept for 30 days');
    $this->postJson($this->base.'/chat/message', ['visitor' => 'short', 'message' => 'Hi'])->assertUnprocessable();
    ($this->in)(fn () => app(ChatBot::class) && Site::query()->firstOrFail()->update(['settings' => ['chat' => ['enabled' => false]]]));
    $this->postJson($this->base.'/chat/message', ['visitor' => 'visitor-key-0123456789', 'message' => 'Hi'])->assertNotFound();
});

it('deletes conversations that led nowhere after 30 days, keeping the ones that did', function () {
    SimulatedDriver::$script = [['text' => 'Hello!'], ['tool' => 'hand_over', 'arguments' => ['name' => 'Sipho', 'email' => 'sipho@example.test', 'message' => 'Call me', 'agreed_to_be_contacted' => true]], ['text' => 'Passed on.']];
    $idle = ($this->say)('Hi');
    $kept = ($this->say)('Get me a person', null, 'another-visitor-0123456789');
    $this->travel(31)->days();
    $this->artisan('website:sweep-conversations')->expectsOutput("Conversations deleted: 1. Voice notes' audio deleted: 0.")->assertSuccessful();
    ($this->in)(fn () => expect(ReceptionistConversation::query()->pluck('id')->all())->toBe([$kept['conversation']])
        ->and(ReceptionistMessage::query()->where('conversation_id', $idle['conversation'])->count())->toBe(0));
});
