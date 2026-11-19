<?php

declare(strict_types=1);

namespace Wurk\Reach\Ussd;

use Carbon\CarbonImmutable;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Platform\Money\Money;
use Wurk\Reach\Models\ReachNumber;
use Wurk\Reach\Models\UssdSession;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * The USSD booking menu (25.2): any phone, no data. The caller dials WURK's code, enters the business's short number
 * (or dials it in the code: *384*1234*5678#), then books a free time, or sees, cancels or moves their bookings.
 *
 * It uses the AI receptionist's booking tools (Sprint 19), so availability, deposits, confirmation messages and
 * "only your own bookings" are exactly the website's. The caller's number comes from the network, so — as on
 * WhatsApp — their own bookings are theirs without a code. One session costs the business one USSD rate in credits,
 * charged once it reaches the business.
 */
final class UssdMenu
{
    /** A dropped session can be picked up again for this long. */
    public const RESUME_SECONDS = 120;

    public function __construct(private TenantContext $context, private TenantDirectory $tenants, private ReceptionistTools $tools,
        private Entitlements $entitlements, private AuditLog $audit) {}

    /** How this session reached WURK: 'ussd' (charged per session) or 'sms' (each reply SMS is charged as a message). */
    private string $channel = 'ussd';

    /** The caller's latest answer moves the session on; the session is saved with its new state. */
    public function handle(UssdSession $session, string $input, bool $first, string $channel = 'ussd'): Screen
    {
        $this->channel = $channel;
        $state = $session->state;
        $input = trim($input);
        if ($first) {
            $state = $this->begin($session, $input);
            if (isset($state['offer_resume'])) {
                $session->state = $state;

                return new Screen('You were booking with '.$state['offer_resume']['name'].'. Carry on?', ['Yes', 'Start again']);
            }
            if (($state['step'] ?? '') === 'home') { // the number was dialled in the code
                return $this->enter($session, $state);
            }
            $session->state = $state;

            return Screen::ask($this->ask());
        }

        return $this->step($session, $state, $input);
    }

    /** @return array<string, mixed> */
    private function begin(UssdSession $session, string $dialled): array
    {
        $fresh = ['step' => 'business', 'conversation' => 'ussd:'.$session->id];
        if ($dialled !== '' && ctype_digit($dialled)) {
            return ['step' => 'home', 'number' => $dialled, 'conversation' => 'ussd:'.$session->id];
        }
        if ($this->channel !== 'ussd') {
            return $fresh; // SMS keeps one conversation for 30 minutes instead
        }
        $dropped = UssdSession::query()->where('provider', '!=', 'sms')->where('phone', $session->phone)->where('id', '!=', $session->id)->where('ended', false)->whereNotNull('tenant_id')
            ->where('updated_at', '>=', now()->subSeconds(self::RESUME_SECONDS))->orderByDesc('updated_at')->first();
        if ($dropped !== null && ! in_array($dropped->state['step'] ?? 'home', ['business', 'home'], true)) {
            $tenant = $this->tenants->find((string) $dropped->tenant_id);
            if ($tenant !== null) {
                $dropped->forceFill(['ended' => true])->save();

                return [...$fresh, 'offer_resume' => ['name' => $tenant->displayName(), 'tenant' => $dropped->tenant_id, 'state' => $dropped->state, 'charged' => $dropped->charged]];
            }
        }

        return $fresh;
    }

    /** @param array<string, mixed> $state */
    private function step(UssdSession $session, array $state, string $in): Screen
    {
        if (isset($state['offer_resume'])) {
            $resume = $state['offer_resume'];
            if ($in === '1') {
                $session->forceFill(['tenant_id' => $resume['tenant'], 'charged' => (bool) $resume['charged']]);
                $session->state = $resume['state'];

                return $this->within($session, fn (CurrentTenant $t) => $this->show($session, $t, $session->state));
            }
            $state = ['step' => 'business', 'conversation' => 'ussd:'.$session->id];
            $session->state = $state;

            return Screen::ask($this->ask());
        }
        if ($state['step'] === 'business') {
            return $this->enter($session, [...$state, 'number' => $in]);
        }

        return $this->within($session, fn (CurrentTenant $t) => $this->answer($session, $t, $state, $in));
    }

    /** @param array<string, mixed> $state */
    private function enter(UssdSession $session, array $state): Screen
    {
        $number = ReachNumber::query()->where('number', (string) ($state['number'] ?? ''))->first();
        $tenant = $number === null ? null : $this->tenants->find($number->tenant_id);
        if ($number === null || $tenant === null || ! $tenant->isActive()) {
            $session->state = ['step' => 'business', 'conversation' => $state['conversation']];

            return Screen::ask("No business has that number.\nEnter the business's number:");
        }
        $on = $this->channel === 'sms' ? $number->sms_on : $number->ussd_on;
        if (! $on || ! $this->entitlements->entitled($tenant->tenantId(), 'bookings')) {
            return $this->finish($session, $tenant->displayName().' doesn’t take bookings by '.($this->channel === 'sms' ? 'SMS' : 'USSD').'. Please call them.');
        }
        $session->forceFill(['tenant_id' => $tenant->tenantId()]);

        return $this->within($session, function (CurrentTenant $t) use ($session, $state) {
            if (! $session->charged && $this->channel === 'ussd') {
                try {
                    app(CreditWallet::class)->charge('ussd', 'ussd:'.$session->id, 'USSD booking session');
                    $session->charged = true;
                } catch (InsufficientCredits|\InvalidArgumentException) { // out of credits, or no USSD rate for this country yet
                    return $this->finish($session, 'Sorry, '.$t->displayName().' can’t take bookings by USSD right now. Please call them.');
                }
            }

            return $this->show($session, $t, ['step' => 'home', 'conversation' => $state['conversation']]);
        });
    }

    /**
     * The caller's answer on a business's screen.
     *
     * @param  array<string, mixed>  $state
     */
    private function answer(UssdSession $session, CurrentTenant $t, array $state, string $in): Screen
    {
        $page = (int) ($state['page'] ?? 0);
        if ($in === '0' && $state['step'] !== 'home') {
            return $this->show($session, $t, $this->back($state));
        }
        if ($in === '8' && ($state['more'] ?? false)) {
            return $this->show($session, $t, [...$state, 'page' => $page + 1]);
        }
        $pick = ctype_digit($in) ? (int) $in - 1 + $page * Screen::PER_PAGE : -1;
        $options = $state['options'] ?? [];

        switch ($state['step']) {
            case 'home':
                return match ($in) {
                    '1' => $this->show($session, $t, ['step' => 'services', 'conversation' => $state['conversation']]),
                    '2' => $this->show($session, $t, ['step' => 'mine', 'conversation' => $state['conversation']]),
                    '3' => $this->show($session, $t, ['step' => 'owed', 'conversation' => $state['conversation']]),
                    '0' => $this->finish($session, 'Thank you. Goodbye.'),
                    default => $this->show($session, $t, $state, 'Choose 1, 2, 3 or 0.'),
                };
            case 'owed':
                return $in === '0' ? $this->show($session, $t, ['step' => 'home', 'conversation' => $state['conversation']]) : $this->finish($session, 'Thank you. Goodbye.');
            case 'services':
                if (! isset($options[$pick])) {
                    return $this->show($session, $t, $state, 'Choose a number from the list.');
                }

                return $this->show($session, $t, [...$state, 'step' => 'days', 'service' => $options[$pick], 'page' => 0, 'mode' => $state['mode'] ?? 'book']);
            case 'days':
                if (! isset($options[$pick])) {
                    return $this->show($session, $t, $state, 'Choose a day from the list.');
                }

                return $this->show($session, $t, [...$state, 'step' => 'times', 'day' => $options[$pick], 'page' => 0]);
            case 'times':
                if (! isset($options[$pick])) {
                    return $this->show($session, $t, $state, 'Choose a time from the list.');
                }
                $next = [...$state, 'starts_at' => $options[$pick]['starts_at'], 'time' => $options[$pick]['label'], 'page' => 0];
                if (($state['mode'] ?? 'book') === 'move') {
                    return $this->show($session, $t, [...$next, 'step' => 'confirm_move']);
                }

                return $this->show($session, $t, [...$next, 'step' => isset($state['name']) ? 'confirm' : 'name']);
            case 'name':
                $name = trim(preg_replace('/\s+/', ' ', $in) ?? '');
                if (mb_strlen($name) < 2 || ctype_digit(str_replace(' ', '', $name))) {
                    return $this->show($session, $t, $state, 'Type your name, please.');
                }

                return $this->show($session, $t, [...$state, 'name' => mb_substr($name, 0, 60), 'step' => 'confirm']);
            case 'confirm':
                return $in === '1' ? $this->book($session, $t, $state) : $this->show($session, $t, ['step' => 'home', 'conversation' => $state['conversation']]);
            case 'mine':
                if (! isset($options[$pick])) {
                    return $this->show($session, $t, $state, 'Choose a booking from the list.');
                }

                return $this->show($session, $t, [...$state, 'step' => 'booking', 'booking' => $options[$pick], 'page' => 0]);
            case 'booking':
                return match ($in) {
                    '1' => $this->show($session, $t, [...$state, 'step' => 'confirm_cancel']),
                    '2' => $this->show($session, $t, [...$state, 'step' => 'days', 'mode' => 'move', 'service' => ['id' => $state['booking']['service'], 'name' => $state['booking']['service']], 'page' => 0]),
                    default => $this->show($session, $t, $state, 'Choose 1, 2 or 0.'),
                };
            case 'confirm_cancel':
                if ($in !== '1') {
                    return $this->show($session, $t, ['step' => 'home', 'conversation' => $state['conversation']]);
                }
                $done = $this->run('cancel_booking', ['booking_id' => $state['booking']['id'], 'confirmed' => true], $state);

                return $this->finish($session, isset($done['refused']) ? $done['refused'] : 'Cancelled: '.$state['booking']['label'].'.'
                    .(($done['deposit_refunded'] ?? false) ? ' Your deposit will be refunded.' : '').' A confirmation is on its way.');
            case 'confirm_move':
                if ($in !== '1') {
                    return $this->show($session, $t, ['step' => 'home', 'conversation' => $state['conversation']]);
                }
                $done = $this->run('move_booking', ['booking_id' => $state['booking']['id'], 'starts_at' => $state['starts_at']], $state);

                return $this->finish($session, isset($done['refused']) ? $done['refused'] : 'Moved to '.$state['day']['label'].' '.$state['time'].'. A confirmation is on its way.');
        }

        return $this->show($session, $t, ['step' => 'home', 'conversation' => $state['conversation']]);
    }

    /**
     * Builds the screen for a state, and keeps in the session what each number on it means.
     *
     * @param  array<string, mixed>  $state
     */
    private function show(UssdSession $session, CurrentTenant $t, array $state, ?string $problem = null): Screen
    {
        $prefix = $problem === null ? '' : $problem."\n";
        $state = [...$state, 'options' => [], 'more' => false];
        [$screen, $state] = match ($state['step']) {
            'services' => $this->list($state, $prefix.'Choose a service:', $this->services($state)),
            'days' => $this->list($state, $prefix.$state['service']['name']."\nChoose a day:", $this->days($state)),
            'times' => $this->list($state, $prefix.$state['day']['label']."\nChoose a time:", $this->times($state)),
            'mine' => $this->list($state, $prefix.'Your bookings:', $this->mine($state), 'You have no bookings coming up here.'),
            'name' => [Screen::ask($prefix.'Your name for the booking:'), $state],
            'confirm' => [new Screen($prefix.'Book '.$state['service']['name'].', '.$state['day']['label'].' '.$state['time']
                .($state['service']['price'] !== null ? ', '.$state['service']['price'] : '').'?', ['Yes', 'No']), $state],
            'booking' => [new Screen($prefix.$state['booking']['label'], ['Cancel it', 'Move it'], back: true), $state],
            'confirm_cancel' => [new Screen($prefix.'Cancel '.$state['booking']['label'].'?', ['Yes, cancel', 'No']), $state],
            'confirm_move' => [new Screen($prefix.'Move to '.$state['day']['label'].' '.$state['time'].'?', ['Yes', 'No']), $state],
            'owed' => [new Screen($prefix.$this->owed($session), back: true), $state],
            default => [new Screen($prefix.$t->displayName()."\n1 Book\n2 My bookings\n3 What I owe\n0 Exit"), [...$state, 'step' => 'home']],
        };
        $session->state = $state;

        return $screen;
    }

    /**
     * A paged list; what each number means is kept in the state.
     *
     * @param  array<string, mixed>  $state
     * @param  list<array{label: string, value: mixed}>  $items
     * @return array{Screen, array<string, mixed>}
     */
    private function list(array $state, string $title, array $items, string $empty = 'Nothing free in the next two weeks.'): array
    {
        if ($items === []) {
            return [new Screen($title."\n".$empty, back: true), $state];
        }
        $page = min((int) ($state['page'] ?? 0), intdiv(count($items) - 1, Screen::PER_PAGE));
        $more = count($items) > ($page + 1) * Screen::PER_PAGE;
        $labels = array_column(array_slice($items, $page * Screen::PER_PAGE, Screen::PER_PAGE), 'label');

        return [new Screen($title, $labels, more: $more, back: true), [...$state, 'page' => $page, 'more' => $more, 'options' => array_column($items, 'value')]];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{label: string, value: array{id: string, name: string, price: string|null}}>
     */
    private function services(array $state): array
    {
        $services = $this->run('services', [], $state)['services'] ?? [];

        return array_values(array_map(fn (array $s) => ['label' => $s['name'].($s['price'] !== null ? ' '.$s['price'] : ''),
            'value' => ['id' => (string) $s['id'], 'name' => (string) $s['name'], 'price' => $s['price']]], array_filter($services, fn (array $s) => ($s['where'] ?? '') !== 'online only')));
    }

    /**
     * The next days with free times (up to two weeks ahead, at most 14 listed).
     *
     * @param  array<string, mixed>  $state
     * @return list<array{label: string, value: array{date: string, label: string}}>
     */
    private function days(array $state): array
    {
        $today = CarbonImmutable::now($this->context->current()->timezone())->startOfDay();
        $out = [];
        for ($d = 0; $d < 14; $d++) {
            $day = $today->addDays($d);
            $free = $this->run('free_times', ['service' => $state['service']['id'], 'date' => $day->toDateString()], $state)['times'] ?? [];
            if ($free !== []) {
                $label = $d === 0 ? 'Today' : ($d === 1 ? 'Tomorrow' : $day->format('D j M'));
                $out[] = ['label' => $label, 'value' => ['date' => $day->toDateString(), 'label' => $label]];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array{label: string, value: array{starts_at: string, label: string}}>
     */
    private function times(array $state): array
    {
        $free = $this->run('free_times', ['service' => $state['service']['id'], 'date' => $state['day']['date']], $state)['times'] ?? [];

        return array_values(array_map(fn (array $f) => ['label' => (string) $f['time'], 'value' => ['starts_at' => (string) $f['starts_at'], 'label' => (string) $f['time']]], $free));
    }

    /**
     * The caller's own upcoming bookings here (their number is given by the network).
     *
     * @param  array<string, mixed>  $state
     * @return list<array{label: string, value: array{id: string, label: string, service: string}}>
     */
    private function mine(array $state): array
    {
        $found = $this->run('find_booking', [], $state)['bookings'] ?? [];

        return array_values(array_map(function (array $b) {
            $label = $b['service'].', '.preg_replace('/^(\w{3})\w*\s(\d+)\s(\w{3})\w*,\s/u', '$1 $2 $3 ', (string) $b['when']); // "Sat 22 Nov 10:00"

            return ['label' => $label, 'value' => ['id' => (string) $b['booking_id'], 'label' => $label, 'service' => (string) $b['service']]];
        }, $found));
    }

    /** What the caller owes this business (unpaid deposits, pay-later orders, sales on account, invoices), by their number. */
    private function owed(UssdSession $session): string
    {
        $digits = fn (?string $p) => ltrim(preg_replace('/\D/', '', (string) $p) ?? '', '0');
        $mine = $digits($session->phone);
        $items = array_values(array_filter(app(MoneyOwed::class)->all(), fn (array $o) => $o['phone'] !== null && $mine !== ''
            && (str_ends_with($mine, $digits($o['phone'])) || str_ends_with($digits($o['phone']), $mine)) && strlen($digits($o['phone'])) >= 9));
        if ($items === []) {
            return 'You owe nothing here.';
        }
        $lines = array_map(fn (array $o) => Money::format((int) $o['amount_cents']).' '.$o['title'], array_slice($items, 0, 4));

        return "You owe:\n".implode("\n", $lines)."\nPay at the business, or with the link in your message.";
    }

    /** @param array<string, mixed> $state */
    private function book(UssdSession $session, CurrentTenant $t, array $state): Screen
    {
        $done = $this->run('book_appointment', ['service' => $state['service']['id'], 'starts_at' => $state['starts_at'], 'name' => $state['name'],
            'phone' => $session->phone, 'agreed_to_be_contacted' => true], $state);
        if (isset($done['refused'])) {
            if (str_contains($done['refused'], 'deposit')) {
                return $this->finish($session, $state['service']['name'].' needs a deposit paid online. Book on '.$t->displayName().'’s website, or call them.');
            }

            return $this->show($session, $t, [...$state, 'step' => 'times', 'page' => 0], $done['refused']);
        }
        $this->audit->record('reach.ussd_booked', 'booking', (string) ($done['booking_id'] ?? ''), ['session' => $session->id]);
        if (($done['awaiting_deposit'] ?? false) === true) {
            return $this->finish($session, 'Held: '.$state['day']['label'].' '.$state['time'].'. Approve the '.$done['deposit'].' deposit on your phone to confirm it.');
        }

        return $this->finish($session, 'Booked: '.$state['service']['name'].', '.$state['day']['label'].' '.$state['time'].'. A confirmation is on its way.');
    }

    /**
     * Runs one of the receptionist's booking tools for this caller.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function run(string $name, array $arguments, array $state): array
    {
        $tool = collect($this->tools->all())->first(fn (ReceptionistTool $t) => $t->name() === $name);
        if ($tool === null) {
            return ['refused' => 'Bookings aren’t available here.'];
        }
        $session = $this->session;
        try {
            return $tool->run($arguments, ['conversation' => (string) $state['conversation'], 'channel' => $this->channel,
                'customer' => ['phone' => $session?->phone, 'verified' => true]]);
        } catch (ReceptionistRefused $e) {
            return ['refused' => $e->getMessage()];
        }
    }

    private ?UssdSession $session = null;

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function back(array $state): array
    {
        $to = match ($state['step']) {
            'services', 'mine', 'owed' => 'home',
            'days' => ($state['mode'] ?? 'book') === 'move' ? 'booking' : 'services',
            'times' => 'days',
            'name' => 'times',
            'booking' => 'mine',
            default => 'home',
        };

        return [...$state, 'step' => $to, 'page' => 0];
    }

    private function ask(): string
    {
        return "WURK bookings\nEnter the business's number:";
    }

    private function finish(UssdSession $session, string $text): Screen
    {
        $session->ended = true;

        return Screen::end($text);
    }

    /** @param callable(CurrentTenant): Screen $then */
    private function within(UssdSession $session, callable $then): Screen
    {
        $tenant = $this->tenants->find((string) $session->tenant_id);
        if ($tenant === null || ! $tenant->isActive()) {
            return $this->finish($session, 'That business can’t take bookings right now.');
        }
        $this->session = $session;

        return $this->context->run($tenant, fn () => $then($tenant));
    }
}
