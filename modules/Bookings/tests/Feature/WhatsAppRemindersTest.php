<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Digest\BookingsDigest;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Listeners\ConfirmPaymentOnWhatsApp;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Receptionist\FindBookingTool;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Messaging\Models\WhatsAppConnection;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistConversation;
use Wurk\Website\Models\ReceptionistMessage;

/*
 * Sprint 21.5: booking reminders from the business's own WhatsApp number with Confirm / Move / Cancel buttons, each tap
 * handled straight away. Meta's Graph API and webhooks are faked and replayed.
 */
uses(InteractsWithShards::class);

const RB_API = 'https://graph.facebook.com/v23.0';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    Mail::fake();
    Event::fake([BookingConfirmed::class]); // these tests send explicitly
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    [$this->service, $this->booking] = ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => '01J00000000000000000000001', 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => '01J00000000000000000000001', 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);
        app(CreditWallet::class)->grant('purchase', 100000, 'buy:1', 'Purchase');

        return [$service, app(Booker::class)->book($service, CarbonImmutable::parse('2027-05-03 09:00', 'Africa/Johannesburg'), ['name' => 'Sipho Ndlovu', 'phone' => '082 555 0101', 'email' => 'sipho@example.com'])];
    });
    app(ProviderSettings::class)->save('whatsapp', 'meta', ['access_token' => 'system-token', 'app_secret' => 'app-secret'], ['phone_number_id' => 'wurk-pn'], true, null);
    $this->connection = WhatsAppConnection::query()->create(['tenant_id' => $this->glow->id, 'waba_id' => 'waba-1', 'phone_number_id' => 'pn-glow', 'token' => 'glow-token',
        'status' => 'connected', 'templates' => [WhatsAppBusiness::REMINDER => 'APPROVED']]);
    $this->sent = 0;
    Http::fake([RB_API.'/pn-glow/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.(++$this->sent)]]])]);
    $this->messages = fn () => collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => str_ends_with($r->url(), '/pn-glow/messages'))->values();
    $this->tap = function (array $message, string $from = '27825550101') {
        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'waba-1', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'pn-glow'], 'contacts' => [['profile' => ['name' => 'Sipho'], 'wa_id' => $from]],
            'messages' => [['from' => $from, 'id' => 'wamid.in'.uniqid(), 'timestamp' => (string) now()->getTimestamp()] + $message]]]]]]]);

        return $this->call('POST', 'http://app.'.config('wurk.domains.root').'/webhooks/messaging/meta', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret')], $body)->assertOk();
    };
    $this->button = fn (string $payload, string $text, string $from = '27825550101') => ($this->tap)(['type' => 'button', 'button' => ['payload' => $payload, 'text' => $text]], $from);
    $this->row = fn (string $id, string $title) => ($this->tap)(['type' => 'interactive', 'interactive' => ['type' => 'list_reply', 'list_reply' => ['id' => $id, 'title' => $title]]]);
    $this->remind = fn () => ($this->in)(fn () => app(BookingMessages::class)->send($this->booking, 'reminder', 'reminder_24h'));
});

it('sends the reminder from the business’s own number with Confirm, Move and Cancel', function () {
    ($this->remind)();
    $r = ($this->messages)()->sole();
    expect($r['to'])->toBe('27825550101')->and($r['type'])->toBe('template')->and($r['template']['name'])->toBe('wurk_reminder')
        ->and($r['template']['components'][0]['parameters'])->toBe([['type' => 'text', 'text' => 'Sipho'], ['type' => 'text', 'text' => 'Knotless braids'],
            ['type' => 'text', 'text' => 'Glow Salon'], ['type' => 'text', 'text' => 'Mon 3 May, 09:00']])
        ->and(array_map(fn ($c) => [$c['sub_type'], $c['index'], $c['parameters'][0]['payload']], array_slice($r['template']['components'], 1)))->toBe([
            ['quick_reply', '0', 'booking:confirm:'.$this->booking->id], ['quick_reply', '1', 'booking:move:'.$this->booking->id], ['quick_reply', '2', 'booking:cancel:'.$this->booking->id]]);
    Mail::assertNotSent(BookingMessageMail::class);
    ($this->in)(fn () => expect((array) DB::connection(app(TenantContext::class)->connectionName())->table('booking_messages')->where('kind', 'reminder_24h')->first(['channel', 'status']))
        ->toBe(['channel' => 'whatsapp', 'status' => 'sent']));
});

it('confirms on a tap, and shows it in the diary', function () {
    ($this->button)('booking:confirm:'.$this->booking->id, 'Confirm');
    expect(($this->messages)()->sole()['text']['body'])->toBe('Thanks, Sipho! You’re confirmed for Monday 3 May at 09:00. See you then.');
    ($this->in)(fn () => expect(Booking::query()->findOrFail($this->booking->id)->customer_confirmed_at)->not->toBeNull());
});

it('offers the next free times on Move, and moves the booking when one is tapped', function () {
    ($this->button)('booking:move:'.$this->booking->id, 'Move');
    $list = ($this->messages)()->sole()['interactive'];
    $rows = $list['action']['sections'][0]['rows'];
    expect($list['type'])->toBe('list')->and($rows)->toHaveCount(10)
        ->and($rows[0])->toBe(['id' => 'booking:moveto:'.$this->booking->id.':'.CarbonImmutable::parse('2027-05-03 10:00', 'Africa/Johannesburg')->getTimestamp(), 'title' => 'Mon 3 May, 10:00', 'description' => 'Knotless braids'])
        ->and($rows[1]['title'])->toBe('Mon 3 May, 10:15');

    ($this->row)($rows[1]['id'], $rows[1]['title']);
    expect(($this->messages)()->last()['text']['body'])->toBe('Done — your Knotless braids is now Monday 3 May at 10:15.');
    ($this->in)(fn () => expect(Booking::query()->findOrFail($this->booking->id)->starts_at->setTimezone('Africa/Johannesburg')->format('Y-m-d H:i'))->toBe('2027-05-03 10:15'));

    ($this->row)($rows[1]['id'], $rows[1]['title']); // tapped twice
    expect(($this->messages)()->last()['text']['body'])->toBe('That’s already your time: Monday 3 May at 10:15. See you then!');
});

it('cancels on a tap and frees the time', function () {
    ($this->button)('booking:cancel:'.$this->booking->id, 'Cancel');
    expect(($this->messages)()->sole()['text']['body'])->toBe('Your Knotless braids on Monday 3 May at 09:00 is cancelled. Reply here any time to book again.')
        ->and(($this->in)(fn () => Booking::query()->findOrFail($this->booking->id)->status))->toBe('cancelled');
    ($this->in)(fn () => expect(collect(app(Availability::class)->on($this->service, '2027-05-03'))->pluck('starts_at')->first())
        ->toBe(CarbonImmutable::parse('2027-05-03 09:00', 'Africa/Johannesburg')->utc()->toIso8601String()));

    ($this->button)('booking:confirm:'.$this->booking->id, 'Confirm'); // an old reminder
    expect(($this->messages)()->last()['text']['body'])->toBe('That booking has already been cancelled or has passed. Reply here if you’d like a new one.');
});

it('asks first when cancelling would lose the deposit', function () {
    ($this->in)(fn () => Booking::query()->whereKey($this->booking->id)->update(['deposit_cents' => 20000, 'paid_at' => now()]));
    $this->travelTo(CarbonImmutable::parse('2027-05-03 08:00', 'Africa/Johannesburg')); // an hour before
    ($this->button)('booking:cancel:'.$this->booking->id, 'Cancel');
    $ask = ($this->messages)()->sole()['interactive'];
    expect($ask['type'])->toBe('button')->and($ask['body']['text'])->toStartWith('It’s too close to your booking for the deposit to be refunded')
        ->and(array_column(array_column($ask['action']['buttons'], 'reply'), 'title'))->toBe(['Yes, cancel', 'Keep my booking'])
        ->and(($this->in)(fn () => Booking::query()->findOrFail($this->booking->id)->status))->toBe('confirmed');
    ($this->tap)(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'booking:cancelyes:'.$this->booking->id, 'title' => 'Yes, cancel']]]);
    expect(($this->in)(fn () => Booking::query()->findOrFail($this->booking->id)->status))->toBe('cancelled');
});

it('lets only the booking’s own customer act on it', function () {
    ($this->button)('booking:cancel:'.$this->booking->id, 'Cancel', '27820000000');
    expect(($this->messages)()->sole()['text']['body'])->toBe('Sorry, I couldn’t find that booking. Reply here and someone from the team will help.')
        ->and(($this->in)(fn () => Booking::query()->findOrFail($this->booking->id)->status))->toBe('confirmed');
});

it('falls back to the usual reminder until Meta approves the template, or when WhatsApp isn’t connected', function () {
    $this->connection->update(['templates' => [WhatsAppBusiness::REMINDER => 'PENDING']]);
    ($this->remind)();
    expect(($this->messages)())->toHaveCount(0);
    Mail::assertSent(BookingMessageMail::class, fn (BookingMessageMail $m) => $m->hasTo('sipho@example.com'));
});

it('shows the reminder in the customer’s WhatsApp conversation in Chats', function () {
    ($this->remind)();
    ($this->button)('booking:confirm:'.$this->booking->id, 'Confirm');
    ($this->in)(fn () => expect(ReceptionistMessage::query()->orderBy('position')->get()->map(fn ($m) => [$m->role, $m->content['content']])->all())->toBe([
        ['assistant', 'Hi Sipho, a reminder of your Knotless braids at Glow Salon on Mon 3 May, 09:00. Tap below to confirm, move or cancel.'],
        ['user', 'Confirm'],
        ['assistant', 'Thanks, Sipho! You’re confirmed for Monday 3 May at 09:00. See you then.'],
    ])->and(ReceptionistConversation::query()->count())->toBe(1));
});

it('confirms in the chat when the deposit for a WhatsApp booking is paid', function () {
    $id = ($this->in)(function () {
        $b = app(Booker::class)->book($this->service, CarbonImmutable::parse('2027-05-03 14:00', 'Africa/Johannesburg'), ['name' => 'Zodwa Mkhize', 'phone' => '+27825550199', 'email' => 'z@example.com'],
            null, source: 'whatsapp', status: 'pending_payment');
        $b->update(['deposit_cents' => 20000, 'paid_at' => now(), 'status' => 'confirmed']);

        return $b->id;
    });
    app(ConfirmPaymentOnWhatsApp::class)->handle(new BookingConfirmed($this->glow->id, $id));
    $text = ($this->messages)()->sole();
    expect($text['to'])->toBe('27825550199')
        ->and($text['text']['body'])->toBe('Payment received — thank you, Zodwa! Your R200.00 deposit is in, and your Knotless braids on Monday 3 May at 14:00 is confirmed.');
    ($this->in)(fn () => expect(ReceptionistMessage::query()->sole()->content['notice'])->toBeTrue());

    app(ConfirmPaymentOnWhatsApp::class)->handle(new BookingConfirmed($this->glow->id, $this->booking->id)); // booked another way, no deposit: nothing
    expect(($this->messages)())->toHaveCount(1);
});

it('finds a WhatsApp customer’s own bookings straight away — their number is verified', function () {
    $r = ($this->in)(fn () => app(FindBookingTool::class)->run([], ['conversation' => 'conv-1', 'channel' => 'whatsapp', 'customer' => ['phone' => '+27825550101', 'verified' => true]]));
    expect($r['verified'])->toBeTrue()->and($r['bookings'])->toHaveCount(1)->and($r['bookings'][0]['booking_id'])->toBe($this->booking->id);
    // Without verification (a website chat), it still sends a code.
    expect(($this->in)(fn () => app(FindBookingTool::class)->run(['phone' => '082 555 0101'], ['conversation' => 'conv-2', 'channel' => 'web', 'customer' => []])))->toHaveKey('code_sent_if_booked');
});

it('counts tomorrow’s bookings and today’s deposits for the owner’s evening summary (21.6)', function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-02 19:00', 'Africa/Johannesburg'));
    ($this->in)(function () {
        Booking::query()->whereKey($this->booking->id)->update(['deposit_cents' => 20000, 'paid_at' => now(), 'payment_reference' => 'dep_online']); // paid online
        expect(app(BookingsDigest::class)->figures(CarbonImmutable::now('Africa/Johannesburg')->startOfDay()))->toBe(['tomorrow_bookings' => 1, 'received_cents' => 20000])
            ->and(app(BookingsDigest::class)->figures(CarbonImmutable::parse('2027-05-03', 'Africa/Johannesburg'))['tomorrow_bookings'])->toBe(0);
    });
});
