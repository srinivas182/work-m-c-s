<?php

declare(strict_types=1);

namespace Wurk\Shifts;

use Carbon\CarbonImmutable;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Platform\Contracts\TodayList;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Shifts\Support\Clock;
use Wurk\Shifts\Support\Roster;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Shifts (Sprint 26.4): the roster built from booking hours, leave and swaps, clocking in and out, and each person's
 * hours, commission and tips for the bookkeeper. Not payroll.
 */
final class ShiftsServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // Today: my shift and the clock button.
        $this->callAfterResolving(TodayList::class, fn (TodayList $t) => $t->register('shifts', 50, function (string $identityId): ?array {
            $member = collect(app(TeamDirectory::class)->roster())->first(fn (array $m) => $m['identity_id'] === $identityId && $m['active']);
            if ($member === null) {
                return null;
            }
            $roster = app(Roster::class);
            $today = CarbonImmutable::now(app(TenantContext::class)->current()->timezone())->startOfDay();
            $shifts = $roster->shifts($today, $today->addDay(), $member['id']);
            $open = app(Clock::class)->open($member['id']);
            if ($shifts === [] && $open === null) {
                return null;
            }
            $handle = app(TenantContext::class)->current()->handle();

            return ['title' => __('My shift'), 'empty' => null, 'link' => ['label' => __('My shifts'), 'url' => "/{$handle}/shifts/mine"],
                'items' => [['id' => 'clock', 'title' => $shifts === [] ? __('No shift today') : implode(', ', array_map(fn (array $s) => $s['starts'].'–'.$s['ends'], $shifts)),
                    'detail' => $open !== null ? __('Clocked in at :time', ['time' => CarbonImmutable::parse($open['in_at'])->setTimezone(app(TenantContext::class)->current()->timezone())->format('H:i')]) : __('Not clocked in'),
                    'time' => null, 'badge' => $open !== null ? __('Working') : null, 'url' => null,
                    'actions' => [$open !== null ? ['label' => __('Clock out'), 'url' => "/{$handle}/shifts/mine/clock-out", 'data' => [], 'tone' => 'secondary']
                        : ['label' => __('Clock in'), 'url' => "/{$handle}/shifts/mine/clock-in", 'data' => [], 'tone' => 'primary']]]]];
        }));
    }

    public function permissions(): array
    {
        return [['key' => 'shifts.manage', 'label' => 'Shifts: the roster, leave and swaps, clock-in rule, commission and team earnings']];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Shifts', 'route' => 'tenant.shifts.roster', 'icon' => 'calendar', 'section' => 'Settings', 'permission' => 'shifts.manage', 'order' => 801],
            ['label' => 'Team earnings', 'route' => 'tenant.shifts.earnings', 'icon' => 'hand-coins', 'section' => 'Settings', 'permission' => 'shifts.manage', 'order' => 802],
            ['label' => 'My shifts', 'route' => 'tenant.shifts.mine', 'icon' => 'clock', 'section' => 'Overview', 'order' => 2],
        ];
    }
}
