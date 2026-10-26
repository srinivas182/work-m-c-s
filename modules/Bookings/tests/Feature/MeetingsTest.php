<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Reminders;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    Mail::fake();
    app(ProviderSettings::class)->save('meetings.google', 'google', ['client_id' => 'gid', 'client_secret' => 'gsecret'], [], true, null);
    app(ProviderSettings::class)->save('meetings.zoom', 'zoom', ['client_id' => 'zid', 'client_secret' => 'zsecret'], [], true, null);
    $this->createShards(1);
    $this->coach = $this->createTenant('life-coach', name: 'Thandi Coaching');
    $this->coach->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->coach, $fn);
    $this->service = ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Coaching session', 'duration_minutes' => 60, 'location_type' => 'online']);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => '01J00000000000000000000001', 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => '01J00000000000000000000001', 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);

        return $service;
    });
    $this->connect = fn (string $provider, array $extra = []) => ($this->in)(fn () => MeetingConnection::query()->create([
        'tenant_id' => app(TenantContext::class)->id(), 'provider' => $provider, 'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => now()->addHour(), ...$extra]));
    $this->book = fn (bool $online = true, string $at = '2027-05-03 09:00') => ($this->in)(function () use ($online, $at) {
        $b = app(Booker::class)->book($this->service, CarbonImmutable::parse($at, 'Africa/Johannesburg'), ['name' => 'Sipho', 'email' => 'sipho@example.com'], status: 'pending_payment');
        $b->update(['online' => $online, 'status' => 'confirmed']);
        event(new BookingConfirmed(app(TenantContext::class)->id(), $b->id));

        return $b->refresh();
    });
});

it('creates a Google Meet for an online booking — the confirmation carries the link', function () {
    Http::fake(['www.googleapis.com/calendar/*' => Http::response(['id' => 'evt1', 'hangoutLink' => 'https://meet.google.com/abc-defg-hij'])]);
    ($this->connect)('google');
    $booking = ($this->book)();
    expect($booking->only(['meeting_provider', 'meeting_id', 'meeting_url']))->toBe(['meeting_provider' => 'google', 'meeting_id' => 'evt1', 'meeting_url' => 'https://meet.google.com/abc-defg-hij']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'conferenceDataVersion=1') && $r['conferenceData']['createRequest']['requestId'] === $booking->id
        && $r['start']['timeZone'] === 'Africa/Johannesburg' && $r->hasHeader('Authorization', 'Bearer tok'));
    Mail::assertSent(BookingMessageMail::class, fn ($m) => str_contains($m->text, 'Join online: https://meet.google.com/abc-defg-hij'));
});

it('refreshes an expired token, moves the meeting with the booking and deletes it on cancellation', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-tok', 'expires_in' => 3600]),
        'www.googleapis.com/calendar/*' => Http::response(['id' => 'evt1', 'hangoutLink' => 'https://meet.google.com/x']),
    ]);
    ($this->connect)('google', ['expires_at' => now()->subMinute()]);
    $booking = ($this->book)();
    Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['grant_type'] === 'refresh_token');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/events?') && $r->hasHeader('Authorization', 'Bearer new-tok'));

    ($this->in)(fn () => app(Booker::class)->move($booking, CarbonImmutable::parse('2027-05-03 11:00', 'Africa/Johannesburg'), null, null));
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/events/evt1') && str_starts_with($r['start']['dateTime'], '2027-05-03T11:00'));
    ($this->in)(fn () => app(Booker::class)->cancel($booking->refresh(), null));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/events/evt1'));
});

it('creates a Zoom meeting with a waiting room', function () {
    Http::fake(['api.zoom.us/*' => Http::response(['id' => 98765, 'join_url' => 'https://zoom.us/j/98765'])]);
    ($this->connect)('zoom');
    expect(($this->book)()->meeting_url)->toBe('https://zoom.us/j/98765');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/users/me/meetings') && $r['settings']['waiting_room'] === true && $r['duration'] === 60);
});

it('keeps the booking when the provider fails, and gets the link before the reminder', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-tok', 'expires_in' => 3600]), // a day later the token has expired
        'www.googleapis.com/calendar/*' => Http::sequence()->push(['error' => 'backendError'], 503)->push(['id' => 'evt2', 'hangoutLink' => 'https://meet.google.com/later']),
    ]);
    ($this->connect)('google');
    $booking = ($this->book)();
    expect($booking->status)->toBe('confirmed')->and($booking->meeting_url)->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2027-05-02 09:01', 'Africa/Johannesburg'));
    app(Reminders::class)->sendDue();
    ($this->in)(fn () => expect($booking->refresh()->meeting_url)->toBe('https://meet.google.com/later'));
});

it('makes no video calls for in-person bookings', function () {
    Http::fake();
    ($this->connect)('google');
    ($this->book)(online: false);
    Http::assertNothingSent();
});
