<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Events\ClassJoined;
use Wurk\Bookings\Events\ClassLeft;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Models\ClassSession;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Places in group classes without overbooking: the session row is locked while places are counted
 * and the attendee saved — a second request for the same class waits its turn.
 */
final readonly class ClassBooker
{
    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    /** @param  array{name: string, phone?: string|null, email?: string|null}  $customer */
    public function attend(ClassSession $session, array $customer, string $source = 'website'): ClassAttendee
    {
        $db = DB::connection($this->context->connectionName());
        $attendee = $db->transaction(function () use ($db, $session, $customer, $source): ClassAttendee {
            $locked = $db->table('class_sessions')->where('id', $session->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'scheduled' || $session->starts_at->isPast()
                || ClassAttendee::query()->where('session_id', $session->id)->where('status', 'confirmed')->count() >= (int) $locked->capacity) {
                throw new ClassFull;
            }

            return ClassAttendee::query()->create([
                'session_id' => $session->id, 'status' => 'confirmed', 'customer_name' => $customer['name'],
                'customer_phone' => $customer['phone'] ?? null, 'customer_email' => $customer['email'] ?? null, 'source' => $source,
            ]);
        });
        $this->audit->record('bookings.class_joined', 'class_attendee', $attendee->id, ['session' => $session->id, 'source' => $source]);
        event(new ClassJoined($this->context->id(), $attendee->id));

        return $attendee;
    }

    public function leave(ClassAttendee $attendee, ?string $by): void
    {
        $attendee->update(['status' => 'cancelled']);
        $this->audit->record('bookings.class_left', 'class_attendee', $attendee->id, ['by' => $by]);
        event(new ClassLeft($this->context->id(), $attendee->id));
    }

    /** The business cancels the session: every attendee's place is cancelled too. */
    public function cancelSession(ClassSession $session, ?string $by): int
    {
        $session->update(['status' => 'cancelled']);
        $attendees = ClassAttendee::query()->where('session_id', $session->id)->where('status', 'confirmed')->get();
        foreach ($attendees as $a) {
            $a->update(['status' => 'cancelled']);
            event(new ClassLeft($this->context->id(), $a->id)); // everyone is told
        }
        $this->audit->record('bookings.class_cancelled', 'class_session', $session->id, ['by' => $by, 'attendees' => $attendees->count()]);

        return $attendees->count();
    }
}
