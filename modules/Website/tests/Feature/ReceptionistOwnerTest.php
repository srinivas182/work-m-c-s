<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ChatHandoff;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Receptionist;

uses(InteractsWithShards::class, InteractsWithIdentity::class);

/** A booking tool that must never run in "Try it". */
final class TryBookTool implements ReceptionistTool
{
    public static int $runs = 0;

    public function name(): string
    {
        return 'book_appointment';
    }

    public function description(): string
    {
        return 'Book an appointment.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new stdClass];
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
        self::$runs++;

        return ['booked' => true];
    }
}

/** Sprint 19.4a: the owner switches the AI receptionist on, chooses what it may do, and tries it safely. */
beforeEach(function () {
    Mail::fake();
    SimulatedDriver::$failNext = 0;
    SimulatedDriver::$script = [];
    SimulatedDriver::$seen = [];
    TryBookTool::$runs = 0;
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Owner', 'owner');
        Site::query()->create(['branding' => [], 'menus' => [], 'settings' => ['enquiry_email' => 'owner@glow.co.za']]);
        app(CreditWallet::class)->grant('purchase', 5000, 'buy:1', 'Purchase');
    });
    app(ReceptionistTools::class)->register(TryBookTool::class);
    $this->base = $this->app('/glow-salon/website/chat');
    $this->settings = ['enabled' => true, 'may' => ['book', 'change'], 'tone' => 'formal', 'greeting' => 'Good day! I’m Glow’s AI assistant.',
        'languages' => ['en', 'zu'], 'person_hours' => "Mon–Fri\n8:00–17:00", 'monthly_cap' => 150];
});

it('shows the price of a reply, what the package allows, and saves the owner’s choices', function () {
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn ($p) => $p->component('Website::Chat')
        ->where('receptionist.settings.enabled', false)->where('receptionist.settings.may', ['book'])
        ->where('receptionist.can', ['book' => true, 'change' => true, 'order' => false]) // no shop in this package
        ->where('receptionist.credits_per_reply', 0.2)->where('receptionist.replies_this_month', 0)
        ->has('receptionist.languages', 8));

    // The assistant answers in the chat, so the chat must be on first.
    $this->actingAs($this->owner)->put("{$this->base}/receptionist", $this->settings)->assertSessionHasErrors(['enabled' => 'Switch the chat on first (Settings above): the assistant answers in it.']);
    ($this->in)(fn () => Site::query()->firstOrFail()->update(['settings' => ['chat' => ['enabled' => true]]]));
    $this->actingAs($this->owner)->put("{$this->base}/receptionist", [...$this->settings, 'languages' => []])->assertSessionHasErrors(['languages' => 'Choose at least one language.']);
    $this->actingAs($this->owner)->put("{$this->base}/receptionist", [...$this->settings, 'may' => ['steal']])->assertSessionHasErrors('may.0');
    $this->actingAs($this->owner)->put("{$this->base}/receptionist", $this->settings)->assertSessionHas('status', 'AI assistant saved — it’s answering in your chat.');

    ($this->in)(fn () => expect(app(Receptionist::class)->settings())->toBe([
        'enabled' => true, 'may' => ['book', 'change'], 'tone' => 'formal', 'greeting' => 'Good day! I’m Glow’s AI assistant.',
        'monthly_cap' => 150, 'languages' => ['en', 'zu'], 'person_hours' => 'Mon–Fri 8:00–17:00',
    ]));
    // The owner's choices reach the assistant's instructions.
    SimulatedDriver::$script = [['text' => 'Sawubona!']];
    $this->actingAs($this->owner)->postJson("{$this->base}/try", ['message' => 'Sawubona'])->assertOk();
    expect(SimulatedDriver::$seen[0]['system'])->toContain('one of: English and isiZulu')->toContain('When a person can take over: Mon–Fri 8:00–17:00.')->toContain('polite and professional');
});

it('tries it with the real lookups but never books, hands over, or counts towards the cap', function () {
    SimulatedDriver::$script = [
        ['tool' => 'book_appointment', 'arguments' => ['service' => 'braids']],
        ['tool' => 'hand_over', 'arguments' => ['name' => 'Owner', 'phone' => '082 555 0101', 'message' => 'test', 'agreed_to_be_contacted' => true]],
        ['text' => 'This is a test chat, so nothing was booked.'],
    ];
    // It works before the assistant is switched on for customers.
    $r = $this->actingAs($this->owner)->postJson("{$this->base}/try", ['message' => 'Book me braids'])->assertOk()->json();
    expect($r['mode'])->toBe('ai')->and($r['reply'])->toBe('This is a test chat, so nothing was booked.')
        ->and(TryBookTool::$runs)->toBe(0)
        ->and(json_encode(SimulatedDriver::$seen[1]['items'], JSON_UNESCAPED_UNICODE))->toContain('owner’s test chat, so nothing was done');
    ($this->in)(function () use ($r) {
        expect(ChatHandoff::query()->count())->toBe(0)
            ->and(ReceptionistConversation::query()->find($r['conversation']))->channel->toBe('try')->outcome->toBe('answered')
            ->and(app(Receptionist::class)->repliesThisMonth())->toBe(0)
            ->and(app(CreditWallet::class)->spentThisMonth())->toBe(200); // a test reply is a real AI reply
    });
    Mail::assertNothingSent();

    // Someone outside the business can't try it.
    $status = $this->actingAs($this->person(['email' => 'stranger@example.com', 'phone' => '+27829999999']))->postJson("{$this->base}/try", ['message' => 'Hi'])->status();
    expect($status)->toBeIn([403, 404]);
});
