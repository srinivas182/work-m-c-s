<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Carbon\CarbonImmutable;
use Wurk\Bookings\Models\ReservationSettings;
use Wurk\Bookings\Support\TableAvailability;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Tenancy\Contracts\TenantContext;

/** Arrival times with room for a party on a day: the restaurant's own table availability. */
final readonly class TableTimesTool implements ReceptionistTool
{
    public function __construct(private TableAvailability $tables, private TenantContext $context) {}

    public function name(): string
    {
        return 'table_times';
    }

    public function description(): string
    {
        return 'Free arrival times for a table on a date, for a party size. Only offer times this returns.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD in the business’s time zone'],
            'party' => ['type' => 'integer', 'description' => 'Number of guests'],
        ], 'required' => ['date', 'party']];
    }

    public function capability(): string
    {
        return 'book';
    }

    public function entitlement(): string
    {
        return 'bookings';
    }

    public function run(array $arguments, array $context): array
    {
        $settings = ReservationSettings::query()->find($this->context->id());
        if ($settings === null || ! $settings->enabled) {
            throw new ReceptionistRefused('This business doesn’t take table bookings online.');
        }
        $party = (int) ($arguments['party'] ?? 0);
        if ($party < 1 || $party > $settings->max_party) {
            throw new ReceptionistRefused("Tables online are for 1 to {$settings->max_party} guests. For more, offer to pass it to a person.");
        }
        $date = (string) ($arguments['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new ReceptionistRefused('Use a date like 2027-05-03.');
        }
        $times = array_slice($this->tables->on($date, $party), 0, 16);

        return ['day' => CarbonImmutable::parse($date, $this->context->current()->timezone())->format('l j F Y'), 'party' => $party,
            'times' => array_map(fn (array $t) => ['starts_at' => $t['starts_at'], 'time' => $t['label']], $times)];
    }
}
