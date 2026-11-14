<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Models\AiRequest;
use Wurk\Ai\Models\AssistantAction;
use Wurk\Ai\Support\AiSettings;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Help\Models\HelpArticle;
use Wurk\Help\Models\HelpCategory;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\AssistantTools;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

/** The simulated AI's next replies, as the JSON "Ask WURK" asks for. */
function aiReplies(array ...$replies): void
{
    SimulatedDriver::$answers = array_map(fn (array $r) => (string) json_encode([...['answer' => '', 'articles' => [], 'tool' => null, 'unanswered' => false], ...$r]), $replies);
}

function assistantSettings(array $over): void
{
    $s = app(AiSettings::class)->all();
    app(AiSettings::class)->save([...$s, 'assistant' => [...$s['assistant'], ...$over]], null);
}

beforeEach(function () {
    SimulatedDriver::$answers = [];
    SimulatedDriver::$nextAnswer = null;
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings', 'marketing', 'reports'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->member($this->staff, $this->glow);
    [$this->thandi, $this->conn] = $this->ctx->run($this->glow, fn () => [(string) app(Team::class)->add($this->owner->id, 'Thandi', 'owner')->id, $this->ctx->connectionName()]);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->staff->id, 'Sam', 'staff'));
    $this->base = $this->app('/glow-salon/_assistant');
    $cat = HelpCategory::query()->create(['name' => 'Team', 'slug' => 'team', 'position' => 1]);
    HelpArticle::query()->create(['category_id' => $cat->id, 'title' => 'Add a team member', 'slug' => 'add-a-team-member', 'summary' => 'Invite staff to your back office.',
        'body' => 'Go to **Team → Invite**, enter their phone or email and choose a role.', 'status' => 'published', 'screens' => []]);
    HelpArticle::query()->create(['category_id' => $cat->id, 'title' => 'Set booking hours', 'slug' => 'set-booking-hours', 'summary' => 'When each team member can be booked.',
        'body' => 'Go to **Booking settings → Hours**.', 'status' => 'published', 'screens' => []]);
});

it('answers how-to questions from the help centre, with the articles it used — and counts what it couldn’t answer', function () {
    aiReplies(['answer' => 'Go to Team → Invite and choose a role.', 'articles' => ['add-a-team-member']]);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'How do I add a team member?', 'screen' => 'Team::Index'])->assertOk()
        ->assertJson(['status' => 'answered', 'answer' => 'Go to Team → Invite and choose a role.', 'free' => true,
            'sources' => [['title' => 'Add a team member', 'url' => 'https://'.config('wurk.domains.help').'/a/add-a-team-member']], 'action' => null])
        ->assertJsonPath('usage.used', 1)->assertJsonPath('usage.free_left', 49);
    expect(AiRequest::query()->where('task', 'assistant')->count())->toBe(1);

    // Nothing covers it: said honestly, and counted (by its words, no person) for WURK to write next.
    aiReplies(['answer' => 'I don’t know that one yet.', 'unanswered' => true]);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'How do I connect my Xero account?'])->assertOk()->assertJson(['answer' => 'I don’t know that one yet.', 'sources' => []]);
    expect(DB::table('help_searches')->where('query', 'how do i connect my xero account?')->value('count'))->toBe(1);

    // No usable reply from the AI: the matching articles instead.
    SimulatedDriver::$nextAnswer = 'not json at all {';
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'team member invite'])->assertOk()->assertJsonPath('answer', 'not json at all {');
    $this->actingAs($this->owner)->getJson($this->base)->assertOk()->assertJsonCount(3, 'history')->assertJsonPath('history.0.question', 'How do I add a team member?')
        ->assertJsonPath('usage.used', 3);
});

it('looks up the business’s own figures read-only, through tools the person may use', function () {
    $this->ctx->run($this->glow, function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60]);
        $tomorrow = now($this->ctx->current()->timezone())->addDay()->setTime(10, 0);
        foreach (['confirmed', 'confirmed', 'cancelled'] as $i => $status) {
            Booking::query()->create(['service_id' => $service->id, 'member_id' => $this->thandi, 'starts_at' => $tomorrow->copy()->addHours($i), 'ends_at' => $tomorrow->copy()->addHours($i + 1),
                'status' => $status, 'customer_name' => 'Lerato Secret', 'customer_phone' => '+27830000001']);
        }
    });
    $day = $this->ctx->run($this->glow, fn () => now($this->ctx->current()->timezone())->addDay()->toDateString());
    aiReplies(['tool' => ['name' => 'bookings_on_day', 'params' => ['date' => $day]]], ['answer' => 'You have 2 bookings tomorrow.']);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'How many bookings do we have tomorrow?'])->assertOk()->assertJson(['answer' => 'You have 2 bookings tomorrow.']);
    expect(AiRequest::query()->where('task', 'assistant')->count())->toBe(2) // asked, then answered from the data
        ->and(DB::connection($this->conn)->table('assistant_questions')->value('tool'))->toBe('bookings_on_day');

    // What the tool hands the AI: times, services, who and status — never the customer.
    $read = $this->ctx->run($this->glow, function () use ($day) {
        auth()->login($this->owner);

        return app(AssistantTools::class)->find('bookings_on_day')->read(['date' => $day]);
    });
    expect($read)->toContain('2 booking(s), 1 cancelled')->toContain('Knotless braids with Thandi (confirmed)')->not->toContain('Lerato')->not->toContain('+2783');

    // Business figures come from the reports this person may see.
    $figures = $this->ctx->run($this->glow, fn () => app(AssistantTools::class)->find('business_figures')->read(['period' => 'this_month']));
    expect($figures)->toContain('Period: ');

    // Staff without the reports permission: the tool isn't offered, so a request for it is ignored.
    aiReplies(['answer' => 'I can’t see your figures.', 'tool' => ['name' => 'business_figures', 'params' => ['period' => 'this_month']]]);
    $this->actingAs($this->staff)->postJson($this->base.'/ask', ['question' => 'What were sales this month?'])->assertOk()->assertJson(['answer' => 'I can’t see your figures.']);
    expect(AiRequest::query()->where('task', 'assistant')->count())->toBe(3);
});

it('proposes a coupon, makes it only when confirmed, logs it and can undo it', function () {
    aiReplies(['answer' => 'Here’s the coupon.', 'tool' => ['name' => 'create_coupon', 'params' => ['code' => 'december10', 'kind' => 'percent', 'value' => 10, 'starts_on' => now()->year.'-12-01', 'ends_on' => now()->year.'-12-31']]]);
    $res = $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'Create a 10% December coupon'])->assertOk()
        ->assertJsonPath('action.summary', 'Create coupon DECEMBER10 (10% off)')->assertJsonPath('action.status', 'proposed')
        ->assertJsonPath('action.changes.0', 'Code: DECEMBER10');
    $id = $res->json('action.id');
    $coupons = fn () => DB::connection($this->conn)->table('coupons')->where('tenant_id', $this->glow->id);
    expect($coupons()->count())->toBe(0); // nothing until confirmed

    $this->actingAs($this->staff)->postJson($this->base."/actions/{$id}/confirm")->assertStatus(422); // not theirs
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/confirm")->assertOk()->assertJson(['status' => 'applied', 'message' => 'Coupon DECEMBER10 is ready.']);
    expect($coupons()->value('value'))->toBe(1000)->and($coupons()->value('starts_at'))->not->toBeNull();
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/confirm")->assertStatus(422)->assertJson(['message' => 'That’s already done.']);
    expect(DB::connection($this->conn)->table('audit_events')->whereIn('action', ['campaigns.coupon_created', 'assistant.change_made'])->count())->toBe(2);

    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/undo")->assertOk()->assertJson(['status' => 'undone']);
    expect($coupons()->count())->toBe(0);
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/undo")->assertStatus(422);

    // A code that exists already is refused before anything is proposed.
    $this->actingAs($this->owner)->post($this->app('/glow-salon/coupons'), ['code' => 'SAVE5', 'kind' => 'amount', 'value' => 5, 'applies_to' => 'shop']);
    aiReplies(['tool' => ['name' => 'create_coupon', 'params' => ['code' => 'SAVE5', 'kind' => 'amount', 'value' => 5]]]);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'Make SAVE5 again'])->assertOk()
        ->assertJson(['answer' => 'You already have a coupon called SAVE5. Choose another code.', 'action' => null]);

    // Once used, it can't be undone (switch it off on Coupons instead).
    aiReplies(['tool' => ['name' => 'create_coupon', 'params' => ['code' => 'USED1', 'kind' => 'amount', 'value' => 20]]]);
    $used = $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'R20 off coupon'])->json('action.id');
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$used}/confirm")->assertOk();
    $this->ctx->run($this->glow, fn () => DB::connection($this->conn)->table('coupon_redemptions')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->glow->id,
        'coupon_id' => $coupons()->where('code', 'USED1')->value('id'), 'source_type' => 'order', 'source_id' => 'o1', 'discount_cents' => 2000, 'created_at' => now()]));
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$used}/undo")->assertStatus(422)->assertJson(['message' => 'This can’t be undone any more — it has been used or changed since. Change it on its own screen instead.']);
});

it('respects permissions and keeps proposals fresh', function () {
    aiReplies(['answer' => 'You can’t make coupons.', 'tool' => ['name' => 'create_coupon', 'params' => ['code' => 'STAFF10', 'kind' => 'percent', 'value' => 10]]]);
    $this->actingAs($this->staff)->postJson($this->base.'/ask', ['question' => 'Create a coupon'])->assertOk()->assertJson(['answer' => 'You can’t make coupons.', 'action' => null]);
    expect($this->ctx->run($this->glow, fn () => AssistantAction::query()->count()))->toBe(0);

    // A proposal is only good for 30 minutes — the data may have moved on.
    aiReplies(['tool' => ['name' => 'create_coupon', 'params' => ['code' => 'OWNER10', 'kind' => 'percent', 'value' => 10]]]);
    $id = $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'Create a coupon'])->json('action.id');
    $this->travel(31)->minutes();
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/confirm")->assertStatus(422)->assertJson(['message' => 'That suggestion is more than 30 minutes old — ask again so it’s up to date.']);
});

it('adds booking hours for a team member by name — checked like Booking settings — and undoes them', function () {
    $this->ctx->run($this->glow, fn () => BookingHours::query()->create(['member_id' => $this->thandi, 'weekday' => 5, 'starts' => '14:00', 'ends' => '17:00']));
    aiReplies(['tool' => ['name' => 'add_booking_hours', 'params' => ['member' => 'Thandi', 'weekday' => 'Saturday', 'starts' => '13:00', 'ends' => '15:00']]]);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'Add Saturday 1–3 for Thandi'])->assertOk()
        ->assertJson(['answer' => 'Thandi already has hours on Saturdays that overlap 14:00–17:00. Change them in Booking settings.', 'action' => null]);

    aiReplies(['tool' => ['name' => 'add_booking_hours', 'params' => ['member' => 'me', 'weekday' => 'saturday', 'starts' => '9', 'ends' => '13:00']]]);
    $res = $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'Add a Saturday 9–1 slot for me'])->assertOk()
        ->assertJsonPath('action.summary', 'Add 09:00–13:00 on Saturdays to Thandi’s booking hours')
        ->assertJsonPath('action.changes.1', 'Already: 14:00–17:00 (unchanged)');
    $id = $res->json('action.id');
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/confirm")->assertOk()->assertJsonPath('action.link', route('tenant.bookings.settings', ['tenant' => 'glow-salon']));
    $hours = fn () => $this->ctx->run($this->glow, fn () => BookingHours::query()->withoutGlobalScope('branch')->where('member_id', $this->thandi)->where('weekday', 5)->orderBy('starts')->pluck('starts')->map(fn ($s) => substr((string) $s, 0, 5))->all());
    expect($hours())->toBe(['09:00', '14:00']);
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/undo")->assertOk();
    expect($hours())->toBe(['14:00']);

    aiReplies(['tool' => ['name' => 'add_booking_hours', 'params' => ['member' => 'Nobody', 'weekday' => 'monday', 'starts' => '09:00', 'ends' => '10:07']]]);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'hours for Nobody'])->assertJson(['answer' => 'I can’t find a team member called Nobody.']);
});

it('drafts a campaign — never sends it — and undoes the draft while it is still a draft', function () {
    aiReplies(['answer' => 'Here’s a draft.', 'tool' => ['name' => 'draft_campaign', 'params' => ['name' => 'Win-back', 'channel' => 'email', 'segment' => 'inactive',
        'subject' => 'We miss you', 'body' => 'It’s been a while — come and see us this month.']]]);
    $res = $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'Draft a win-back campaign'])->assertOk()
        ->assertJsonPath('action.summary', 'Save the draft campaign “Win-back”')->assertJsonPath('action.changes.4', 'Nothing is sent: you’ll test and send it from Campaigns.');
    $id = $res->json('action.id');
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/confirm")->assertOk();
    $campaigns = fn () => DB::connection($this->conn)->table('campaigns')->where('tenant_id', $this->glow->id);
    expect($campaigns()->value('status'))->toBe('draft')->and($campaigns()->value('segment'))->toBe('inactive');
    $campaigns()->update(['status' => 'sent']);
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/undo")->assertStatus(422);
    $campaigns()->update(['status' => 'draft']);
    $this->actingAs($this->owner)->postJson($this->base."/actions/{$id}/undo")->assertOk();
    expect($campaigns()->count())->toBe(0);

    aiReplies(['tool' => ['name' => 'draft_campaign', 'params' => ['channel' => 'sms', 'segment' => 'all', 'body' => str_repeat('x', 301)]]]);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'long sms'])->assertJson(['answer' => 'SMS messages are kept to 300 characters.']);
});

it('gives the free questions, then asks before spending a credit each, refunds failures, and stops at the cap', function () {
    assistantSettings(['free_questions' => 1, 'monthly_cap' => 3]);
    aiReplies(['answer' => 'One.']);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'first question'])->assertOk()->assertJson(['free' => true]);

    // No credits: refused, nothing charged or asked.
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'second question'])->assertStatus(422)->assertJson(['buy' => true]);

    $wallet = fn () => $this->ctx->run($this->glow, fn () => app(CreditWallet::class));
    $this->ctx->run($this->glow, fn () => app(CreditWallet::class)->grant('purchase', 5000, 'buy:1', 'Purchase'));
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'second question'])->assertOk()
        ->assertJson(['status' => 'quote', 'credits' => 1, 'balance' => 5, 'message' => 'You’ve used this month’s 1 free questions. This one uses 1 credit (you have 5).']);
    expect(AiRequest::query()->count())->toBe(1);

    aiReplies(['answer' => 'Two.']);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'second question', 'accept_charge' => true])->assertOk()->assertJson(['answer' => 'Two.', 'free' => false])
        ->assertJsonPath('usage.balance', 4);

    // WURK AI fails: the credit comes back and the question doesn't count.
    SimulatedDriver::$failNext = 1;
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'third question', 'accept_charge' => true])->assertStatus(503);
    expect($this->ctx->run($this->glow, fn () => app(CreditWallet::class)->balance()['balance_milli']))->toBe(4000);

    aiReplies(['answer' => 'Three.']);
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'third question', 'accept_charge' => true])->assertOk();
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'fourth question', 'accept_charge' => true])->assertStatus(422)
        ->assertJson(['message' => 'You’ve asked 3 questions this month — the most for one month. It starts again on the 1st.']);
});

it('is in every back office header unless WURK switches it off — and WURK sets the numbers in Admin', function () {
    $this->actingAs($this->owner)->get($this->app('/glow-salon/coupons'))
        ->assertInertia(fn (Assert $page) => $page->where('assistant.url', route('tenant.assistant', ['tenant' => 'glow-salon'])));

    $staff = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $settings = [...AiSettings::DEFAULTS, 'assistant' => ['enabled' => false, 'free_questions' => 20, 'monthly_cap' => 10]];
    $this->actingAs($staff)->put($this->admin('/settings/ai'), $settings)->assertSessionHasErrors(['assistant.monthly_cap' => 'The most questions a month can’t be fewer than the free ones.']);
    $this->actingAs($staff)->put($this->admin('/settings/ai'), [...$settings, 'assistant' => ['enabled' => false, 'free_questions' => 20, 'monthly_cap' => 200]])->assertSessionHasNoErrors();
    expect(app(AiSettings::class)->all()['assistant'])->toBe(['enabled' => false, 'free_questions' => 20, 'monthly_cap' => 200]);

    $this->actingAs($this->owner)->get($this->app('/glow-salon/coupons'))->assertInertia(fn (Assert $page) => $page->where('assistant', null));
    $this->actingAs($this->owner)->postJson($this->base.'/ask', ['question' => 'hello there'])->assertStatus(422)->assertJson(['status' => 'refused']);
});
