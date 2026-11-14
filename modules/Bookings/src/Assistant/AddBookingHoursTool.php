<?php

declare(strict_types=1);

namespace Wurk\Bookings\Assistant;

use Wurk\Access\Contracts\BranchRestriction;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Platform\Contracts\AssistantActionTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Tenancy\Contracts\BranchDirectory;

/**
 * "Ask WURK" (16.2): add a stretch of weekly booking hours for a team member ("add a Saturday 9–1 slot for Thandi"),
 * checked as Booking settings checks hours — 5-minute steps, end after start, no overlap on that day at any branch.
 * Undo removes exactly that stretch, if it's still there unchanged.
 */
final readonly class AddBookingHoursTool implements AssistantActionTool
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(private TeamDirectory $team, private BranchDirectory $branches, private BranchRestriction $restriction, private AuditLog $audit) {}

    public function name(): string
    {
        return 'add_booking_hours';
    }

    public function description(): string
    {
        return 'Add weekly hours when a team member can be booked (e.g. Saturdays 09:00–13:00). Use "me" for the person asking.';
    }

    public function parameters(): array
    {
        return [
            'member' => 'the team member’s name, or "me"',
            'weekday' => 'monday … sunday',
            'starts' => 'HH:MM, 24-hour',
            'ends' => 'HH:MM, 24-hour',
            'branch' => 'branch name (only if the business has several; leave out for the main branch)',
        ];
    }

    public function permission(): string
    {
        return 'bookings.manage';
    }

    public function entitlement(): string
    {
        return 'bookings';
    }

    public function preview(array $params): array
    {
        $p = $this->check($params);
        $existing = BookingHours::query()->withoutGlobalScope('branch')->where('member_id', $p['member_id'])->where('weekday', $p['weekday'])->orderBy('starts')->get(['starts', 'ends']);
        $changes = ["{$p['member']} — ".ucfirst(self::DAYS[$p['weekday']])."s: {$p['starts']}–{$p['ends']} (new)".($p['branch'] !== null ? " at {$p['branch']}" : '')];
        foreach ($existing as $e) {
            $changes[] = 'Already: '.substr((string) $e->starts, 0, 5).'–'.substr((string) $e->ends, 0, 5).' (unchanged)';
        }

        return ['summary' => "Add {$p['starts']}–{$p['ends']} on ".ucfirst(self::DAYS[$p['weekday']])."s to {$p['member']}’s booking hours", 'changes' => $changes];
    }

    public function apply(array $params): array
    {
        $p = $this->check($params);
        $row = BookingHours::query()->create(['member_id' => $p['member_id'], 'branch_id' => $p['branch_id'], 'weekday' => $p['weekday'], 'starts' => $p['starts'], 'ends' => $p['ends']]);
        $this->audit->record('bookings.hours_updated', 'member', $p['member_id'], ['added' => ucfirst(self::DAYS[$p['weekday']])." {$p['starts']}–{$p['ends']}", 'branch' => $p['branch_id'], 'via' => 'Ask WURK']);

        return ['done' => "Added. {$p['member']} can now be booked on ".ucfirst(self::DAYS[$p['weekday']])."s {$p['starts']}–{$p['ends']}.",
            'link' => route('tenant.bookings.settings'), 'undo' => ['id' => $row->id, 'member_id' => $p['member_id'], 'weekday' => $p['weekday'], 'starts' => $p['starts'], 'ends' => $p['ends']]];
    }

    public function undo(array $undo): bool
    {
        $row = BookingHours::query()->withoutGlobalScope('branch')->whereKey((string) ($undo['id'] ?? ''))->first();
        if ($row === null || $row->member_id !== ($undo['member_id'] ?? null) || substr((string) $row->starts, 0, 5) !== ($undo['starts'] ?? null) || substr((string) $row->ends, 0, 5) !== ($undo['ends'] ?? null)) {
            return false; // the hours were changed since (Booking settings replaces them as a whole)
        }
        $row->delete();
        $this->audit->record('bookings.hours_updated', 'member', $row->member_id, ['removed' => ucfirst(self::DAYS[$row->weekday]).' '.$undo['starts'].'–'.$undo['ends'], 'via' => 'Ask WURK (undo)']);

        return true;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{member_id: string, member: string, weekday: int, starts: string, ends: string, branch_id: string, branch: string|null}
     */
    private function check(array $params): array
    {
        [$memberId, $member] = $this->member(trim((string) ($params['member'] ?? '')));
        $weekday = array_search(strtolower(trim((string) ($params['weekday'] ?? ''))), self::DAYS, true);
        if ($weekday === false) {
            throw new AssistantRefused('Which day of the week?');
        }
        $starts = $this->time($params['starts'] ?? null);
        $ends = $this->time($params['ends'] ?? null);
        if ($ends <= $starts) {
            throw new AssistantRefused('The end must be after the start.');
        }
        [$branchId, $branch] = $this->branch(trim((string) ($params['branch'] ?? '')));
        // A person can't be in two places: their hours on that day at every branch count.
        foreach (BookingHours::query()->withoutGlobalScope('branch')->where('member_id', $memberId)->where('weekday', $weekday)->get(['starts', 'ends']) as $other) {
            if (substr((string) $other->starts, 0, 5) < $ends && substr((string) $other->ends, 0, 5) > $starts) {
                throw new AssistantRefused("{$member} already has hours on ".ucfirst(self::DAYS[$weekday]).'s that overlap '.substr((string) $other->starts, 0, 5).'–'.substr((string) $other->ends, 0, 5).'. Change them in Booking settings.');
            }
        }

        return ['member_id' => $memberId, 'member' => $member, 'weekday' => (int) $weekday, 'starts' => $starts, 'ends' => $ends, 'branch_id' => $branchId, 'branch' => $branch];
    }

    /** @return array{string, string} */
    private function member(string $name): array
    {
        $members = $this->team->members();
        if (in_array(strtolower($name), ['me', 'my', 'myself', 'i'], true)) {
            $me = (string) auth()->id();
            foreach ($this->team->roster() as $r) {
                if ($r['identity_id'] === $me) {
                    return [$r['id'], $r['name']];
                }
            }
            throw new AssistantRefused('You’re not on this business’s team.');
        }
        $needle = mb_strtolower($name);
        $exact = array_values(array_filter($members, fn (array $m) => mb_strtolower($m['name']) === $needle));
        $found = $exact !== [] ? $exact : array_values(array_filter($members, fn (array $m) => $needle !== '' && str_starts_with(mb_strtolower($m['name']), $needle)));
        if (count($found) === 1) {
            return [$found[0]['id'], $found[0]['name']];
        }
        if ($found === []) {
            throw new AssistantRefused($name === '' ? 'For which team member?' : "I can’t find a team member called {$name}.");
        }
        throw new AssistantRefused('Which one — '.implode(' or ', array_column($found, 'name')).'?');
    }

    private function time(mixed $value): string
    {
        $v = trim((string) $value);
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?$/', $v, $m) !== 1 || (int) $m[1] > 23 || (int) ($m[2] ?? 0) > 59 || (int) ($m[2] ?? 0) % 5 !== 0) {
            throw new AssistantRefused('Give times like 09:00 or 13:30 (steps of 5 minutes).');
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) ($m[2] ?? 0));
    }

    /** @return array{string, string|null} */
    private function branch(string $name): array
    {
        $allowed = $this->restriction->branchRestriction((string) auth()->id());
        $open = array_values(array_filter($this->branches->open(), fn (array $b) => $allowed === null || in_array($b['id'], $allowed, true)));
        if ($open === []) {
            throw new AssistantRefused('You can’t set hours at any branch.');
        }
        if ($name === '') {
            $main = array_values(array_filter($open, fn (array $b) => $b['main']))[0] ?? $open[0];

            return [$main['id'], $this->branches->several() ? $main['name'] : null];
        }
        foreach ($open as $b) {
            if (mb_strtolower($b['name']) === mb_strtolower($name)) {
                return [$b['id'], $b['name']];
            }
        }
        throw new AssistantRefused("I can’t find a branch called {$name} that you can set hours at.");
    }
}
