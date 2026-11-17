<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithShards;
use Wurk\Ai\Drivers\SimulatedDriver;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\Site;
use Wurk\Website\Support\Receptionist;

uses(InteractsWithShards::class, FakesYoco::class);

const CHAT_STYLIST = '01J00000000000000000000001';

/** Sprint 19.2b: the AI receptionist's booking tools — the same rules as the booking form, and only the chat's own bookings change. */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    $this->fakeYoco();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->glow, $fn);
    [$this->braids, $this->colour] = ($this->in)(function () {
        $braids = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000]);
        $colour = BookingService::query()->create(['name' => 'Colour', 'duration_minutes' => 60, 'price_cents' => 60000, 'deposit_type' => 'fixed', 'deposit_value' => 10000]);
        foreach ([$braids, $colour] as $s) {
            DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $s->id, 'member_id' => CHAT_STYLIST, 'tenant_id' => app(TenantContext::class)->id()]);
        }
        BookingHours::query()->create(['member_id' => CHAT_STYLIST, 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']); // Mondays

        return [$braids, $colour];
    });
    $this->tool = fn (string $name): ReceptionistTool => collect(app(ReceptionistTools::class)->all())->first(fn (ReceptionistTool $t) => $t->name() === $name);
    $this->run = fn (string $name, array $args, string $conversation = 'conv-1') => ($this->in)(fn () => ($this->tool)($name)->run($args, ['conversation' => $conversation, 'channel' => 'web', 'customer' => []]));
    $this->book = ['service' => 'knotless braids', 'starts_at' => '2027-05-03T07:00:00+00:00', 'name' => 'Sipho', 'phone' => '082 555 0101', 'agreed_to_be_contacted' => true];
});

it('offers the bookable services and only real free times, in local time', function () {
    $s = ($this->run)('services', []);
    expect(array_column($s['services'], 'name'))->toBe(['Colour', 'Knotless braids'])
        ->and(collect($s['services'])->firstWhere('name', 'Knotless braids')['price'])->toBe('R450.00');
    $t = ($this->run)('free_times', ['service' => 'braids', 'date' => '2027-05-03']);
    expect($t['day'])->toBe('Monday 3 May 2027')->and($t['times'][0])->toBe(['starts_at' => '2027-05-03T07:00:00+00:00', 'time' => '09:00'])
        ->and(($this->run)('free_times', ['service' => 'braids', 'date' => '2027-05-04'])['times'])->toBe([]); // Tuesday: closed
    expect(fn () => ($this->run)('free_times', ['service' => 'massage', 'date' => '2027-05-03']))->toThrow(ReceptionistRefused::class, 'no service like that');
});

it('books like the booking form: a free time, a name and number, consent — and never a time that isn’t free', function () {
    expect(fn () => ($this->run)('book_appointment', [...$this->book, 'agreed_to_be_contacted' => false]))->toThrow(ReceptionistRefused::class, 'agree to be contacted');
    expect(fn () => ($this->run)('book_appointment', [...$this->book, 'phone' => 'call me']))->toThrow(ReceptionistRefused::class, 'mobile number');
    expect(fn () => ($this->run)('book_appointment', [...$this->book, 'starts_at' => '2027-05-04T07:00:00+00:00']))->toThrow(ReceptionistRefused::class, 'just been taken');

    $r = ($this->run)('book_appointment', $this->book);
    expect($r['booked'])->toBeTrue()->and($r['when'])->toBe('Monday 3 May, 09:00')->and($r['card']['action']['url'])->toContain('/book/manage/');
    ($this->in)(fn () => expect(Booking::query()->sole())->source->toBe('chat')->status->toBe('confirmed')->customer_phone->toBe('082 555 0101'));
    expect(fn () => ($this->run)('book_appointment', $this->book))->toThrow(ReceptionistRefused::class, 'just been taken'); // the same time again
});

it('takes a deposit with a payment link, and holds the time until it’s paid', function () {
    $this->connectYoco($this->glow);
    $args = [...$this->book, 'service' => $this->colour->id];
    expect(fn () => ($this->run)('book_appointment', $args))->toThrow(ReceptionistRefused::class, 'ask for their email');
    $r = ($this->run)('book_appointment', [...$args, 'email' => 'sipho@example.com']);
    expect($r['booked'])->toBeFalse()->and($r['awaiting_deposit'])->toBeTrue()->and($r['deposit'])->toBe('R100.00')->and($r['pay_url'])->toStartWith('https://')
        ->and($r['card']['action']['label'])->toBe('Pay the deposit');
    ($this->in)(fn () => expect(Booking::query()->sole()->status)->toBe('pending_payment'));
});

it('moves and cancels only bookings made in the same chat', function () {
    $r = ($this->run)('book_appointment', $this->book);
    expect(fn () => ($this->run)('move_booking', ['booking_id' => $r['booking_id'], 'starts_at' => '2027-05-03T09:00:00+00:00'], 'someone-else'))
        ->toThrow(ReceptionistRefused::class, 'only change a booking made in this chat or found with a code');

    expect(($this->run)('move_booking', ['booking_id' => $r['booking_id'], 'starts_at' => '2027-05-03T09:00:00+00:00'])['when'])->toBe('Monday 3 May, 11:00');
    $ask = ($this->run)('cancel_booking', ['booking_id' => $r['booking_id'], 'confirmed' => false]);
    expect($ask['cancelled'])->toBeFalse()->and($ask['policy'])->not->toBe('');
    expect(($this->run)('cancel_booking', ['booking_id' => $r['booking_id'], 'confirmed' => true])['cancelled'])->toBeTrue();
    ($this->in)(fn () => expect(Booking::query()->sole()->status)->toBe('cancelled'));
    expect(fn () => ($this->run)('cancel_booking', ['booking_id' => $r['booking_id'], 'confirmed' => true]))->toThrow(ReceptionistRefused::class, 'can’t be changed any more');
});

it('books through the website chat end to end: real times, a booking card, and the chat marked as booked', function () {
    SimulatedDriver::$failNext = 0;
    SimulatedDriver::$seen = [];
    $plan = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $plan->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    ($this->in)(function () {
        Site::query()->create(['branding' => [], 'menus' => [], 'settings' => ['chat' => ['enabled' => true]]]);
        app(Receptionist::class)->save(['enabled' => true]); // default: answer and book
        app(CreditWallet::class)->grant('purchase', 5000, 'buy:1', 'Purchase');
    });
    $say = fn (string $text, ?string $conversation = null) => $this->postJson('http://glow-salon.'.config('wurk.domains.root').'/chat/message',
        ['visitor' => 'visitor-key-0123456789', 'message' => $text, 'conversation' => $conversation])->assertOk()->json();

    SimulatedDriver::$script = [['tool' => 'free_times', 'arguments' => ['service' => 'braids', 'date' => '2027-05-03']], ['text' => 'On Monday I have 09:00 or 09:30. Which suits you?']];
    $first = $say('Knotless braids on Monday?');
    expect($first['mode'])->toBe('ai')->and($first['reply'])->toContain('09:00')
        ->and(SimulatedDriver::$seen[0]['tools'])->toContain('services', 'free_times', 'book_appointment')->not->toContain('cancel_booking'); // changing is off by default

    SimulatedDriver::$script = [['tool' => 'book_appointment', 'arguments' => $this->book], ['text' => 'You’re booked for Monday 3 May, 09:00. See you then!']];
    $r = $say('9 please. Sipho, 082 555 0101, and yes you can contact me', $first['conversation']);
    expect($r['reply'])->toContain('09:00')->and($r['cards'])->toHaveCount(1)->and($r['cards'][0]['action']['url'])->toContain('/book/manage/');
    ($this->in)(function () use ($r) {
        expect(Booking::query()->sole())->source->toBe('chat')->customer_name->toBe('Sipho')
            ->and(ReceptionistConversation::query()->find($r['conversation']))->outcome->toBe('booked')->keep->toBeTrue();
    });
});

it('finds a booking made elsewhere only with a code sent to the booking’s own phone or email', function () {
    Mail::fake();
    $sms = new class implements Messenger
    {
        /** @var list<array{0: string, 1: string}> */
        public array $sent = [];

        public function sms(string $to, string $text, string $purpose): SendResult
        {
            $this->sent[] = [$to, $text];

            return SendResult::sent('sms-1');
        }

        public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
        {
            return SendResult::sent('wa-1');
        }

        public function channelActive(string $channel): bool
        {
            return true;
        }
    };
    app()->instance(Messenger::class, $sms);
    $booking = ($this->in)(fn () => app(Booker::class)->book($this->braids, CarbonImmutable::parse('2027-05-03 09:00', 'Africa/Johannesburg'), ['name' => 'Sipho', 'phone' => '082 555 0101', 'email' => 'sipho@example.com']));
    expect(fn () => ($this->run)('cancel_booking', ['booking_id' => $booking->id, 'confirmed' => true]))->toThrow(ReceptionistRefused::class, 'found with a code');

    // Someone else's number: the same answer, and nothing is sent — the chat can't reveal who has booked.
    $none = ($this->run)('find_booking', ['phone' => '083 000 0000']);
    expect($none['say'])->toStartWith('If there’s an upcoming booking')->and($sms->sent)->toBe([]);
    Mail::assertNotSent(BookingMessageMail::class, fn (BookingMessageMail $m) => str_contains($m->text, 'code is'));

    // No credits for an SMS: the code goes free to the booking's email instead.
    expect(($this->run)('find_booking', ['phone' => '+27 82 555 0101'])['say'])->toBe($none['say']);
    $code = null;
    Mail::assertSent(BookingMessageMail::class, function (BookingMessageMail $m) use (&$code) {
        if (preg_match('/code is (\d{6})/', $m->text, $match) !== 1) {
            return false; // the booking's own confirmation
        }
        $code = $match[1];

        return $m->hasTo('sipho@example.com');
    });
    expect(fn () => ($this->run)('confirm_booking_code', ['code' => $code], 'someone-else'))->toThrow(ReceptionistRefused::class, 'no code waiting');
    expect(fn () => ($this->run)('confirm_booking_code', ['code' => $code === '000000' ? '111111' : '000000']))->toThrow(ReceptionistRefused::class, 'isn’t right');
    $ok = ($this->run)('confirm_booking_code', ['code' => " {$code} "]);
    expect($ok['bookings'])->toBe([['booking_id' => $booking->id, 'service' => 'Knotless braids', 'when' => 'Monday 3 May, 09:00']]);
    expect(($this->run)('cancel_booking', ['booking_id' => $booking->id, 'confirmed' => true])['cancelled'])->toBeTrue();
    expect(fn () => ($this->run)('confirm_booking_code', ['code' => $code]))->toThrow(ReceptionistRefused::class, 'no code waiting'); // used once

    // With credits, by SMS to the booking's number; a few codes an hour at most.
    ($this->in)(fn () => app(CreditWallet::class)->grant('purchase', 5000, 'buy:1', 'Purchase'));
    ($this->run)('find_booking', ['phone' => '082 555 0101'], 'conv-3');
    expect($sms->sent)->toHaveCount(0); // that booking is cancelled now: nothing upcoming
    $again = ($this->in)(fn () => app(Booker::class)->book($this->braids, CarbonImmutable::parse('2027-05-03 10:00', 'Africa/Johannesburg'), ['name' => 'Sipho', 'phone' => '082 555 0101']));
    ($this->run)('find_booking', ['phone' => '082 555 0101'], 'conv-3');
    expect($sms->sent)->toHaveCount(1)->and($sms->sent[0][0])->toBe('+27825550101')->and($again->id)->not->toBeNull();
    ($this->run)('find_booking', ['phone' => '082 555 0101'], 'conv-3');
    expect(fn () => ($this->run)('find_booking', ['phone' => '082 555 0101'], 'conv-3'))->toThrow(ReceptionistRefused::class, 'Several codes');
});
