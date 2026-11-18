<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Carbon\CarbonImmutable;
use Wurk\Bookings\Support\ManageLink;
use Wurk\Bookings\Support\Reserver;
use Wurk\Bookings\Support\TableFull;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Tenancy\Contracts\TenantContext;

/** Reserve a table at an offered time, as the website's table booking does (capacity re-checked under a lock). */
final readonly class ReserveTableTool implements ReceptionistTool
{
    public function __construct(private ChatBookings $chat, private Reserver $reserver, private TenantContext $context) {}

    public function name(): string
    {
        return 'reserve_table';
    }

    public function description(): string
    {
        return 'Reserve a table at a time table_times returned, after the customer said yes to the day, time and party size and agreed to be contacted. Needs their name and mobile number.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'starts_at' => ['type' => 'string', 'description' => 'The "starts_at" from table_times'],
            'party' => ['type' => 'integer'],
            'notes' => ['type' => 'string', 'description' => 'E.g. a birthday, a high chair'],
            ...ChatBookings::contactProperties(),
        ], 'required' => ['starts_at', 'party', 'name', 'phone', 'agreed_to_be_contacted']];
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
        $customer = $this->chat->customer($arguments, false, 'the table booking');
        try {
            $startsAt = CarbonImmutable::parse((string) ($arguments['starts_at'] ?? ''));
        } catch (\Throwable) {
            throw new ReceptionistRefused('Use a starts_at exactly as table_times returned it.');
        }
        try {
            $r = $this->reserver->reserve($startsAt, (int) ($arguments['party'] ?? 0),
                [...$customer, 'notes' => mb_substr((string) ($arguments['notes'] ?? ''), 0, 500) ?: null], ChatBookings::source($context));
        } catch (TableFull) {
            throw new ReceptionistRefused('There’s no longer room at that time. Check table_times again and offer the nearest ones.');
        }
        $this->chat->remember($context['conversation'], 'reservation', $r->id);
        $when = $this->chat->local($r->starts_at)->format('l j F, H:i');

        return ['booked' => true, 'party' => $r->party_size, 'when' => $when,
            'tell_the_customer' => 'Booked. A confirmation is on its way, with a link to change or cancel.',
            'card' => ['type' => 'table', 'service' => 'Table for '.$r->party_size, 'when' => $when,
                'action' => ['label' => 'Change or cancel', 'url' => ManageLink::forTable($r, $this->context->current()->handle())]]];
    }
}
