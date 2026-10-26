<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\ClassSession;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * A video meeting per online appointment, hosted by the business's own Google (Meet) or Zoom
 * account — or its fixed link. Created on confirmation, moved and deleted with the booking. A failure
 * never undoes the booking: it's logged and retried (before reminders).
 */
final readonly class Meetings
{
    private const GOOGLE_EVENTS = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

    private const ZOOM_MEETINGS = 'https://api.zoom.us/v2';

    public function __construct(private TenantContext $context, private ProviderCredentials $providers, private AuditLog $audit) {}

    /** Make sure an online booking has its link. Safe to call again (does nothing once it has one). */
    public function ensure(Booking $booking): void
    {
        if (! $booking->online || $booking->meeting_url !== null) {
            return;
        }
        $connection = MeetingConnection::query()->find($this->context->id());
        if ($connection === null) {
            return;
        }
        try {
            [$id, $url] = $this->create($connection, $this->title($booking), CarbonImmutable::instance($booking->starts_at), CarbonImmutable::instance($booking->ends_at), $booking->id);
            if ($url !== null && $url !== '') {
                $booking->update(['meeting_provider' => $connection->provider, 'meeting_id' => $id, 'meeting_url' => $url]);
            }
        } catch (Throwable $e) {
            $this->audit->record('bookings.meeting_failed', 'booking', $booking->id, ['provider' => $connection->provider, 'error' => mb_substr($e->getMessage(), 0, 150)]);
        }
    }

    public function moved(Booking $booking): void
    {
        $connection = MeetingConnection::query()->find($this->context->id());
        if (! $booking->online || $booking->meeting_id === null || $connection === null || $connection->provider !== $booking->meeting_provider) {
            $this->ensure($booking);

            return;
        }
        try {
            match ($connection->provider) {
                'google' => $this->google($connection)->patch(self::GOOGLE_EVENTS.'/'.rawurlencode($booking->meeting_id), $this->googleTimes(CarbonImmutable::instance($booking->starts_at), CarbonImmutable::instance($booking->ends_at)))->throw(),
                'zoom' => $this->zoom($connection)->patch(self::ZOOM_MEETINGS.'/meetings/'.rawurlencode($booking->meeting_id), $this->zoomTimes(CarbonImmutable::instance($booking->starts_at), CarbonImmutable::instance($booking->ends_at)))->throw(),
                default => null,
            };
        } catch (Throwable $e) {
            $this->audit->record('bookings.meeting_failed', 'booking', $booking->id, ['provider' => $connection->provider, 'error' => mb_substr($e->getMessage(), 0, 150)]);
        }
    }

    public function cancelled(Booking $booking): void
    {
        $connection = MeetingConnection::query()->find($this->context->id());
        if ($booking->meeting_id === null || $connection === null || $connection->provider !== $booking->meeting_provider) {
            return;
        }
        try {
            match ($connection->provider) {
                'google' => $this->google($connection)->delete(self::GOOGLE_EVENTS.'/'.rawurlencode($booking->meeting_id)),
                'zoom' => $this->zoom($connection)->delete(self::ZOOM_MEETINGS.'/meetings/'.rawurlencode($booking->meeting_id)),
                default => null,
            };
        } catch (Throwable) {
            // The booking is cancelled either way; a leftover calendar entry is harmless.
        }
    }

    /** One meeting for an online class session, shared by everyone attending. Safe to call again. */
    public function ensureForClass(ClassSession $session): void
    {
        if ($session->location_type !== 'online' || $session->meeting_url !== null) {
            return;
        }
        $connection = MeetingConnection::query()->find($this->context->id());
        if ($connection === null) {
            return;
        }
        try {
            [$id, $url] = $this->create($connection, $session->title, CarbonImmutable::instance($session->starts_at), CarbonImmutable::instance($session->ends_at), $session->id);
            if ($url !== null && $url !== '') {
                $session->update(['meeting_provider' => $connection->provider, 'meeting_id' => $id, 'meeting_url' => $url]);
            }
        } catch (Throwable $e) {
            $this->audit->record('bookings.meeting_failed', 'class_session', $session->id, ['provider' => $connection->provider, 'error' => mb_substr($e->getMessage(), 0, 150)]);
        }
    }

    /** @return array{0: string|null, 1: string|null} */
    private function create(MeetingConnection $c, string $title, CarbonImmutable $start, CarbonImmutable $end, string $requestId): array
    {
        if ($c->provider === 'google') {
            $r = $this->google($c)->post(self::GOOGLE_EVENTS.'?conferenceDataVersion=1', [
                'summary' => $title, ...$this->googleTimes($start, $end),
                'conferenceData' => ['createRequest' => ['requestId' => $requestId, 'conferenceSolutionKey' => ['type' => 'hangoutsMeet']]],
            ])->throw();

            return [(string) $r->json('id'), (string) $r->json('hangoutLink')];
        }
        if ($c->provider === 'zoom') {
            $r = $this->zoom($c)->post(self::ZOOM_MEETINGS.'/users/me/meetings', [
                'topic' => $title, 'type' => 2, ...$this->zoomTimes($start, $end),
                'settings' => ['join_before_host' => false, 'waiting_room' => true],
            ])->throw();

            return [(string) $r->json('id'), (string) $r->json('join_url')];
        }

        return [null, $c->fixed_url];
    }

    /** @return array<string, mixed> */
    private function googleTimes(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $tz = $this->context->current()->timezone();

        return ['start' => ['dateTime' => $start->setTimezone($tz)->toIso8601String(), 'timeZone' => $tz],
            'end' => ['dateTime' => $end->setTimezone($tz)->toIso8601String(), 'timeZone' => $tz]];
    }

    /** @return array<string, mixed> */
    private function zoomTimes(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return ['start_time' => $start->utc()->format('Y-m-d\TH:i:s\Z'), 'duration' => (int) $start->diffInMinutes($end), 'timezone' => 'UTC'];
    }

    private function title(Booking $b): string
    {
        return BookingService::query()->whereKey($b->service_id)->value('name').' — '.$b->customer_name;
    }

    private function google(MeetingConnection $c): PendingRequest
    {
        return Http::timeout(15)->acceptJson()->withToken($this->fresh($c, 'https://oauth2.googleapis.com/token', 'meetings.google'));
    }

    private function zoom(MeetingConnection $c): PendingRequest
    {
        return Http::timeout(15)->acceptJson()->withToken($this->fresh($c, 'https://zoom.us/oauth/token', 'meetings.zoom'));
    }

    /** The access token, refreshed first if it has (nearly) expired. */
    private function fresh(MeetingConnection $c, string $tokenUrl, string $channel): string
    {
        if ($c->expires_at === null || $c->expires_at->isAfter(now()->addMinute())) {
            return (string) $c->access_token;
        }
        $creds = (array) ($this->providers->active($channel)['credentials'] ?? []);
        try {
            $r = Http::asForm()->timeout(15)->withBasicAuth((string) ($creds['client_id'] ?? ''), (string) ($creds['client_secret'] ?? ''))
                ->post($tokenUrl, ['grant_type' => 'refresh_token', 'refresh_token' => (string) $c->refresh_token,
                    'client_id' => (string) ($creds['client_id'] ?? ''), 'client_secret' => (string) ($creds['client_secret'] ?? '')])->throw();
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Couldn’t reach the video provider: '.$e->getMessage());
        }
        $c->update(['access_token' => (string) $r->json('access_token'), 'expires_at' => now()->addSeconds((int) $r->json('expires_in', 3600)),
            'refresh_token' => (string) ($r->json('refresh_token') ?: $c->refresh_token)]);

        return (string) $c->access_token;
    }
}
