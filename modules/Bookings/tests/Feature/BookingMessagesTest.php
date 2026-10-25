<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

/** Stands in for WURK's providers; records what would be sent. */
final class FakeMessenger implements Messenger
{
    /** @var list<array{0: string, 1: string, 2: mixed}> */
    public array $sent = [];

    public bool $fail = false;

    public function sms(string $to, string $text, string $purpose): SendResult
    {
        $this->sent[] = ['sms', $to, $text];

        return $this->fail ? SendResult::failed('network down') : SendResult::sent('sms-1');
    }

    public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
    {
        $this->sent[] = ['whatsapp', $template, $parameters];

        return SendResult::sent('wa-1');
    }

    public function channelActive(string $channel): bool
    {
        return true;
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    Mail::fake();
    Event::fake([BookingConfirmed::class]); // these tests send explicitly
    $this->messenger = new FakeMessenger;
    app()->instance(Messenger::class, $this->messenger);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $this->booking = ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => '01J00000000000000000000001', 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => '01J00000000000000000000001', 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);

        return app(Booker::class)->book($service, CarbonImmutable::parse('2027-05-03 09:00', 'Africa/Johannesburg'), ['name' => 'Sipho Ndlovu', 'phone' => '082 555 0101', 'email' => 'sipho@example.com']);
    });
    $this->channel = fn (string $c) => ($this->in)(fn () => BookingSettings::query()->updateOrCreate(['tenant_id' => app(TenantContext::class)->id()], ['message_channel' => $c]));
    $this->send = fn (string $kind = 'confirmed') => ($this->in)(fn () => app(BookingMessages::class)->send($this->booking, $kind));
    $this->record = fn (string $kind = 'confirmed') => ($this->in)(fn () => (array) DB::connection(app(TenantContext::class)->connectionName())->table('booking_messages')->where('kind', $kind)->first(['channel', 'status', 'detail']));
    $this->balance = fn () => ($this->in)(fn () => app(CreditWallet::class)->balance()['balance_milli']);
});

it('emails by default, free, in the fixed wording with the manage link', function () {
    ($this->send)();
    Mail::assertSent(BookingMessageMail::class, fn (BookingMessageMail $m) => $m->hasTo('sipho@example.com')
        && $m->subjectLine === 'Booking confirmed — Glow Salon'
        && str_starts_with($m->text, 'Hi Sipho, your Knotless braids booking at Glow Salon is confirmed for Mon 3 May, 09:00. Change or cancel: http')
        && str_contains($m->text, '/book/manage/'));
    expect(($this->record)())->toBe(['channel' => 'email', 'status' => 'sent', 'detail' => null]);
});

it('sends by SMS on credits — once — in the fixed wording', function () {
    ($this->channel)('sms');
    ($this->in)(fn () => app(CreditWallet::class)->grant('purchase', 10000, 'buy:1', 'Purchase'));
    ($this->send)();
    ($this->send)(); // triggered twice: sent once
    expect($this->messenger->sent)->toHaveCount(1)
        ->and($this->messenger->sent[0][1])->toBe('+27825550101')
        ->and($this->messenger->sent[0][2])->toStartWith('Hi Sipho, your Knotless braids booking at Glow Salon is confirmed for Mon 3 May, 09:00.')
        ->and(($this->balance)())->toBe(7000)
        ->and(($this->record)())->toBe(['channel' => 'sms', 'status' => 'sent', 'detail' => null]);
    Mail::assertNothingSent();
});

it('refunds a failed SMS and falls back to email', function () {
    ($this->channel)('sms');
    ($this->in)(fn () => app(CreditWallet::class)->grant('purchase', 10000, 'buy:1', 'Purchase'));
    $this->messenger->fail = true;
    ($this->send)();
    expect(($this->balance)())->toBe(10000)->and(($this->record)())->toBe(['channel' => 'email', 'status' => 'sent', 'detail' => 'Fell back to email: the sms didn’t send']);
    Mail::assertSent(BookingMessageMail::class);
});

it('never lets a message silently stop when credits run out — it goes by email', function () {
    ($this->channel)('sms'); // no credits at all
    ($this->send)();
    expect($this->messenger->sent)->toBe([])->and(($this->record)())->toBe(['channel' => 'email', 'status' => 'sent', 'detail' => 'Fell back to email: not enough credits']);
});

it('uses the registered WhatsApp template with parameters in order', function () {
    ($this->channel)('whatsapp');
    ($this->in)(fn () => app(CreditWallet::class)->grant('purchase', 10000, 'buy:1', 'Purchase'));
    ($this->send)('cancelled');
    expect($this->messenger->sent[0])->toBe(['whatsapp', 'wurk_booking_cancelled', ['Sipho', 'Knotless braids', 'Glow Salon', 'Mon 3 May, 09:00']]);
});
