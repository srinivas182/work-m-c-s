<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Access\Models\Member;
use Wurk\Access\Models\Role;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Ai\Drivers\SimulatedSpeechDriver;
use Wurk\Ai\Models\AssistantAction;
use Wurk\Ai\Models\AssistantQuestion;
use Wurk\Ai\Support\AiSettings;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\WurkWhatsApp;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\BranchStock;
use Wurk\Support\Support\HelpLine;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 26.1: tell WURK what to do, on WhatsApp. The owner's message (or voice note) becomes Ask WURK with the
 * business's tools, acting as them: questions answered straight away; a change read back with Yes / No buttons and
 * made only after Yes. Charged at the AI receptionist's rate, capped per business per day, and audited. Someone
 * without the permission — or without permission for that change — can't make it.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function instructionReplies(array ...$replies): void
{
    SimulatedDriver::$answers = array_map(fn (array $r) => (string) json_encode([...['answer' => '', 'articles' => [], 'tool' => null, 'unanswered' => false], ...$r]), $replies);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-05 09:00', 'Africa/Johannesburg')); // a Wednesday
    SimulatedDriver::$answers = [];
    SimulatedDriver::$systems = [];
    SimulatedSpeechDriver::$script = [];
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings', 'shop', 'marketing', 'reports'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);

    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->owner->forceFill(['phone_verified_at' => now()])->save();
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'sam@glow.co.za']);
    $this->staff->forceFill(['phone_verified_at' => now()])->save();
    $this->member($this->owner, $this->glow);
    $this->member($this->staff, $this->glow);
    [$this->zodwa, $this->service] = ($this->in)(function () {
        $zodwa = (string) app(Team::class)->add($this->owner->id, 'Zodwa', 'owner')->id;
        app(Team::class)->add($this->staff->id, 'Sam', 'staff');
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 120, 'price_cents' => 45000]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $zodwa, 'tenant_id' => $this->glow->id]);
        app(CreditWallet::class)->grant('purchase', 100000, 'buy:1', 'Purchase');

        return [$zodwa, $service];
    });

    $this->wa = new class implements WurkWhatsApp
    {
        /** @var list<array{string, string}> */
        public array $texts = [];

        /** @var list<array{string, string, array<string, string>}> */
        public array $buttons = [];

        public function available(): bool
        {
            return true;
        }

        public function sendText(string $to, string $text): bool
        {
            $this->texts[] = [$to, $text];

            return true;
        }

        public function sendTemplate(string $to, string $template, array $parameters, string $language = 'en'): bool
        {
            return true;
        }

        public function sendButtons(string $to, string $body, array $buttons): bool
        {
            $this->buttons[] = [$to, $body, $buttons];

            return true;
        }

        public function media(string $mediaId): ?array
        {
            return ['contents' => 'ogg', 'mime' => 'audio/ogg'];
        }
    };
    $this->app->instance(WurkWhatsApp::class, $this->wa);
    $this->say = fn (string $text, string $from = '27831000000', ?string $media = null, ?string $button = null) => app(HelpLine::class)->handle($from, $text, $media, $media === null ? null : 'audio/ogg', $button);
    $this->booking = ['name' => 'book_customer', 'params' => ['customer' => 'Thandi Mokoena', 'phone' => '082 555 0101', 'service' => 'braids', 'date' => '2027-05-08', 'time' => '10:00']];
});

it('reads back a booking from a message, makes it only after Yes, and audits it — charged at the receptionist’s rate', function () {
    instructionReplies(['answer' => 'Sure.', 'tool' => $this->booking]);
    ($this->say)('Book Thandi for braids Saturday 10am');

    expect(($this->in)(fn () => Booking::query()->count()))->toBe(0); // nothing until Yes
    [$to, $body, $buttons] = $this->wa->buttons[0];
    expect($to)->toBe('27831000000')->and($body)->toContain('Book Thandi Mokoena for Knotless braids, Saturday 8 May at 10:00')->toContain('Tap Yes to do it.')
        ->and(array_values($buttons))->toBe(['Yes, do it', 'No, cancel']);
    expect(SimulatedDriver::$systems[0])->toContain('book_customer')->toContain('move_booking')->toContain('mark_order_paid');

    ($this->say)('Yes, do it', button: array_key_first($buttons));
    $booking = ($this->in)(fn () => Booking::query()->sole());
    expect($booking->only(['customer_name', 'member_id', 'status', 'source']))->toBe(['customer_name' => 'Thandi Mokoena', 'member_id' => $this->zodwa, 'status' => 'confirmed', 'source' => 'owner'])
        ->and($booking->created_by)->toBe($this->owner->id)
        ->and(end($this->wa->texts)[1])->toStartWith('✅ Booked: Thandi Mokoena, Knotless braids, Sat 8 May 10:00.');
    ($this->in)(function () {
        expect(AssistantQuestion::query()->sole()->only(['channel', 'free', 'charged_milli']))->toBe(['channel' => 'owner', 'free' => false, 'charged_milli' => app(CreditWallet::class)->rateFor('ai.receptionist')]);
        expect(DB::connection(app(TenantContext::class)->connectionName())->table('audit_events')->whereIn('action', ['assistant.whatsapp_instruction', 'assistant.change_made'])->count())->toBe(2);
    });

    // The same tap again changes nothing.
    ($this->say)('Yes, do it', button: array_key_first($buttons));
    expect(end($this->wa->texts)[1])->toBe('That’s already done.')->and(($this->in)(fn () => Booking::query()->count()))->toBe(1);
});

it('understands a voice note, and "No" cancels without changing anything', function () {
    SimulatedSpeechDriver::$script = [['text' => 'Book Thandi for braids on Saturday at ten', 'language' => 'en', 'confidence' => 0.93]];
    instructionReplies(['tool' => $this->booking]);
    ($this->say)('', media: '1234');
    expect($this->wa->buttons)->toHaveCount(1);
    expect(($this->in)(fn () => AssistantQuestion::query()->value('question')))->toBe('Book Thandi for braids on Saturday at ten');

    ($this->say)('No'); // typed, while the question is waiting
    expect(end($this->wa->texts)[1])->toBe('OK — nothing was changed.')
        ->and(($this->in)(fn () => [Booking::query()->count(), AssistantAction::query()->value('status')]))->toBe([0, 'cancelled']);
});

it('moves a booking, marks a pay-later order paid, and adds stock — each read back first', function () {
    ($this->in)(function () {
        app(Booker::class)->book($this->service, CarbonImmutable::parse('2027-05-05 15:00', 'Africa/Johannesburg'), ['name' => 'Lerato Molefe'], $this->zodwa, asOwner: true);
        $branch = app(BranchDirectory::class)->open()[0]['id'];
        $product = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'active', 'options' => ['Size'], 'position' => 1]);
        foreach (['S', 'M'] as $i => $size) {
            $v = Variant::query()->create(['product_id' => $product->id, 'values' => ['Size' => $size], 'label' => $size, 'price_cents' => 15000, 'stock' => 0, 'position' => $i]);
            app(BranchStock::class)->set($v, [$branch => 2]);
        }
        Order::query()->forceCreate(['number' => 1042, 'status' => 'awaiting_payment', 'payment_method' => 'offline', 'fulfilment' => 'collection', 'customer_name' => 'Sipho Dlamini',
            'customer_phone' => '+27825550202', 'subtotal_cents' => 30000, 'delivery_cents' => 0, 'total_cents' => 30000, 'branch_id' => $branch]);
    });
    $yes = function () {
        ($this->say)('Yes, do it', button: array_key_first(end($this->wa->buttons)[2]));

        return end($this->wa->texts)[1];
    };

    instructionReplies(['tool' => ['name' => 'move_booking', 'params' => ['time' => '15:00', 'new_time' => '16:00']]]);
    ($this->say)('Move my 3 o’clock to 4');
    expect(end($this->wa->buttons)[1])->toContain('Move Lerato Molefe’s Knotless braids from Wed 5 May 15:00 to Wed 5 May 16:00');
    expect($yes())->toStartWith('✅ Moved: Lerato Molefe, now Wed 5 May 16:00.');

    instructionReplies(['tool' => ['name' => 'mark_order_paid', 'params' => ['order' => '1042', 'how' => 'cash']]]);
    ($this->say)('Mark order 1042 paid cash');
    expect(end($this->wa->buttons)[1])->toContain('Mark order #1042 paid — Cash')->toContain('counted in today’s cash-up');
    expect($yes())->toStartWith('✅ Order #1042 is marked paid (Cash).');
    expect(($this->in)(fn () => Order::query()->where('number', 1042)->value('status')))->toBe('paid');

    instructionReplies(['tool' => ['name' => 'add_stock', 'params' => ['product' => 'Kasi T-shirt', 'variant' => 'M', 'quantity' => 20]]]);
    ($this->say)('Add 20 Kasi T-shirts, size M');
    expect(end($this->wa->buttons)[1])->toContain('Add 20 × Kasi T-shirt M')->toContain('In stock: 2 → 22');
    expect($yes())->toStartWith('✅ Added 20 × Kasi T-shirt M. In stock now: 22.');
});

it('answers questions straight away; keeps changes to people with permission; and stops at the daily cap', function () {
    // Staff without the WhatsApp permission get the help line: the help centre only, never the business's tools.
    instructionReplies(['answer' => 'Only the owner can do that here. Ask them, or use the app.']);
    ($this->say)('Book Thandi for braids Saturday 10am', '27831000002');
    expect(SimulatedDriver::$systems[0])->not->toContain('book_customer')
        ->and(end($this->wa->texts)[1])->toContain('Only the owner can do that here.')->and($this->wa->buttons)->toBe([]);

    // Given the WhatsApp permission but not bookings, the booking tool still isn't offered.
    ($this->in)(function () {
        $role = Role::query()->create(['name' => 'Front desk', 'permissions' => ['assistant.whatsapp', 'contacts.view']]);
        app(Team::class)->assign(Member::query()->where('identity_id', $this->staff->id)->sole(), 'custom:'.$role->id);
    });
    SimulatedDriver::$systems = [];
    instructionReplies(['answer' => 'You can’t make bookings — ask the owner.']);
    ($this->say)('Book Thandi for braids Saturday 10am', '27831000002');
    expect(SimulatedDriver::$systems[0])->not->toContain('book_customer');

    // The owner's question is answered straight away (no buttons).
    instructionReplies(['answer' => 'You have 0 bookings tomorrow.']);
    ($this->say)('How many bookings tomorrow?');
    expect(end($this->wa->texts)[1])->toBe('You have 0 bookings tomorrow.');

    $s = app(AiSettings::class)->all();
    app(AiSettings::class)->save([...$s, 'assistant' => [...$s['assistant'], 'free_questions' => 0, 'whatsapp_daily_cap' => 1]], null);
    ($this->in)(fn () => AssistantQuestion::query()->update(['channel' => 'owner'])); // as if today's were instructions
    ($this->say)('How many bookings on Friday?');
    expect(end($this->wa->texts)[1])->toContain('the most for one day');
});
