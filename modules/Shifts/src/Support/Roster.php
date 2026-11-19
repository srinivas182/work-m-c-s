<?php

declare(strict_types=1);

namespace Wurk\Shifts\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Platform\Contracts\TeamHours;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * The weekly roster (Sprint 26.4): built from each person's booking hours so nothing is typed twice, changed by the
 * owner, with leave and swaps asked for by the team and approved by the owner.
 */
final readonly class Roster
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private TeamHours $hours, private AuditLog $audit) {}

    /** The Monday of the week holding `$date` (the business's time zone). */
    public function monday(?string $date = null): CarbonImmutable
    {
        $tz = $this->context->current()->timezone();
        $d = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date, $tz) : CarbonImmutable::now($tz);

        return $d->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /** @return list<array{id: string, member_id: string, branch_id: string|null, on: string, starts: string, ends: string}> */
    public function shifts(CarbonImmutable $from, CarbonImmutable $until, ?string $memberId = null): array
    {
        return array_values(array_map(fn ($r) => ['id' => (string) $r->id, 'member_id' => (string) $r->member_id, 'branch_id' => $r->branch_id, 'on' => (string) $r->on,
            'starts' => (string) $r->starts, 'ends' => (string) $r->ends],
            $this->db()->table('shifts')->where('tenant_id', $this->context->id())->where('on', '>=', $from->toDateString())->where('on', '<', $until->toDateString())
                ->when($memberId !== null, fn ($q) => $q->where('member_id', $memberId))->orderBy('on')->orderBy('starts')->get()->all()));
    }

    /** Fills the week from everyone's booking hours; returns how many shifts were added (existing ones and leave are kept). */
    public function fill(CarbonImmutable $monday, string $by): int
    {
        $active = array_column(array_filter($this->team->roster(), fn (array $m) => $m['active']), 'id');
        $added = 0;
        foreach ($this->hours->weekly() as $h) {
            if (! in_array($h['member_id'], $active, true) || $h['weekday'] < 0 || $h['weekday'] > 6) {
                continue;
            }
            $on = $monday->addDays($h['weekday'])->toDateString();
            if ($this->onLeave($h['member_id'], $on)) {
                continue;
            }
            $added += $this->insert($h['member_id'], $h['branch_id'], $on, $h['starts'], $h['ends'], $by) ? 1 : 0;
        }
        $this->audit->record('shifts.filled', 'roster', $monday->toDateString(), ['added' => $added, 'by' => $by]);

        return $added;
    }

    public function add(string $memberId, ?string $branchId, string $on, string $starts, string $ends, string $by): void
    {
        if (! in_array($memberId, array_column($this->team->roster(), 'id'), true)) {
            throw ValidationException::withMessages(['member_id' => __('Choose someone on the team.')]);
        }
        if ($ends <= $starts) {
            throw ValidationException::withMessages(['ends' => __('The end must be after the start.')]);
        }
        if (! $this->insert($memberId, $branchId, $on, $starts, $ends, $by)) {
            throw ValidationException::withMessages(['starts' => __('They already have a shift starting then.')]);
        }
        $this->audit->record('shifts.added', 'member', $memberId, ['on' => $on, 'starts' => $starts, 'ends' => $ends, 'by' => $by]);
    }

    public function remove(string $shiftId, string $by): void
    {
        $this->db()->table('shifts')->where('tenant_id', $this->context->id())->where('id', $shiftId)->delete();
        $this->audit->record('shifts.removed', 'shift', $shiftId, ['by' => $by]);
    }

    public function askLeave(string $memberId, string $from, string $until, ?string $note): void
    {
        if ($until < $from) {
            throw ValidationException::withMessages(['until' => __('The last day can’t be before the first.')]);
        }
        $this->db()->table('shift_requests')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'member_id' => $memberId, 'kind' => 'leave',
            'from' => $from, 'until' => $until, 'note' => $note, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function askSwap(string $memberId, string $shiftId, string $withMember, ?string $note): void
    {
        $shift = $this->db()->table('shifts')->where('tenant_id', $this->context->id())->where('id', $shiftId)->where('member_id', $memberId)->first();
        if ($shift === null || (string) $shift->on < CarbonImmutable::now($this->context->current()->timezone())->toDateString()) {
            throw ValidationException::withMessages(['shift_id' => __('Choose one of your coming shifts.')]);
        }
        if ($withMember === $memberId || ! in_array($withMember, array_column(array_filter($this->team->roster(), fn (array $m) => $m['active']), 'id'), true)) {
            throw ValidationException::withMessages(['swap_with' => __('Choose who will take it.')]);
        }
        $this->db()->table('shift_requests')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'member_id' => $memberId, 'kind' => 'swap',
            'shift_id' => $shiftId, 'swap_with' => $withMember, 'note' => $note, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The owner approves or declines. Approved leave clears those days' shifts; an approved swap hands the shift over. */
    public function decide(string $requestId, bool $approve, string $by): void
    {
        $r = $this->db()->table('shift_requests')->where('tenant_id', $this->context->id())->where('id', $requestId)->where('status', 'pending')->first()
            ?? throw ValidationException::withMessages(['request' => __('That request has already been answered.')]);
        DB::connection($this->context->connectionName())->transaction(function () use ($r, $approve, $by): void {
            if ($approve && $r->kind === 'leave') {
                $this->db()->table('shifts')->where('tenant_id', $this->context->id())->where('member_id', $r->member_id)->whereBetween('on', [(string) $r->from, (string) $r->until])->delete();
            }
            if ($approve && $r->kind === 'swap') {
                $this->db()->table('shifts')->where('tenant_id', $this->context->id())->where('id', $r->shift_id)->update(['member_id' => $r->swap_with, 'updated_at' => now()]);
            }
            $this->db()->table('shift_requests')->where('tenant_id', $this->context->id())->where('id', $r->id)
                ->update(['status' => $approve ? 'approved' : 'declined', 'decided_by' => $by, 'decided_at' => now(), 'updated_at' => now()]);
        });
        $this->audit->record($approve ? 'shifts.request_approved' : 'shifts.request_declined', 'shift_request', (string) $r->id, ['kind' => $r->kind, 'by' => $by]);
    }

    /** @return list<array<string, mixed>> */
    public function requests(?string $memberId = null, ?string $status = null): array
    {
        return array_values(array_map(fn ($r) => (array) $r, $this->db()->table('shift_requests')->where('tenant_id', $this->context->id())
            ->when($memberId !== null, fn ($q) => $q->where('member_id', $memberId))->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit(50)->get()->all()));
    }

    private function onLeave(string $memberId, string $on): bool
    {
        return $this->db()->table('shift_requests')->where('tenant_id', $this->context->id())->where('member_id', $memberId)->where('kind', 'leave')->where('status', 'approved')
            ->where('from', '<=', $on)->where('until', '>=', $on)->exists();
    }

    private function insert(string $memberId, ?string $branchId, string $on, string $starts, string $ends, string $by): bool
    {
        return $this->db()->table('shifts')->insertOrIgnore(['id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'member_id' => $memberId, 'branch_id' => $branchId,
            'on' => $on, 'starts' => $starts, 'ends' => $ends, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]) > 0;
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->context->connectionName());
    }
}
