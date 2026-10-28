<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Bookings\Models\MemberMeetingLink;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Which meeting link an online appointment or class uses: the team member's own Google Meet or Zoom link,
 * otherwise the business default (pasted links — agreed with WURK as simpler than connecting accounts).
 */
final readonly class MeetingLinks
{
    public function __construct(private TenantContext $context) {}

    public function forMember(?string $memberId): ?string
    {
        $own = $memberId === null ? null : MemberMeetingLink::query()->find($memberId)?->url;

        return $own ?? $this->default();
    }

    public function default(): ?string
    {
        $c = MeetingConnection::query()->find($this->context->id());

        return $c !== null && $c->provider === 'fixed' ? $c->fixed_url : null;
    }

    /** Can every member who does this service be reached online? (Their own link, or a default.) */
    public function covers(string $serviceId): bool
    {
        if ($this->default() !== null || MeetingConnection::query()->find($this->context->id()) !== null) {
            return true;
        }
        $members = DB::connection($this->context->connectionName())->table('booking_service_member')->where('service_id', $serviceId)->pluck('member_id');

        return $members->isNotEmpty() && MemberMeetingLink::query()->whereIn('member_id', $members)->count() === $members->count();
    }
}
