<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Models\CreditBalance;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Reach\Models\ReachNumber;
use Wurk\Reach\Models\UssdSession;
use Wurk\Reach\Support\ReachNumbers;
use Wurk\Reach\Ussd\Screen;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 25.2a: booking from any phone by USSD. One WURK code; the caller enters the business's short number,
 * books a free time (the website's rules, through the receptionist's tools), and sees, cancels or moves their own
 * bookings — the network gives their number. Each screen fits 182 characters; a session costs the business credits.
 */

uses(InteractsWithShards::class);

const USSD_STYLIST = '01J0000000000000000000USSD';
const USSD_TOKEN = 'ussd-secret-token-1234';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg')); // a Saturday
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->glow, $fn);
    ($this->in)(function () {
        $braids = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $braids->id, 'member_id' => USSD_STYLIST, 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => USSD_STYLIST, 'weekday' => 0, 'starts' => '09:00', 'ends' => '11:00']); // Mondays, two times
        app(CreditWallet::class)->grant('purchase', 10000, 'buy:1', 'Purchase');
    });
    $this->number = app(ReachNumbers::class)->for($this->glow->id)->number;
    app(ProviderSettings::class)->save('ussd', 'africastalking', ['username' => 'wurk', 'api_key' => 'at-key'], ['service_code' => '*384*1234#', 'webhook_token' => USSD_TOKEN], true, null);
    $this->url = 'http://'.config('wurk.domains.app').'/webhooks/ussd/africastalking/'.USSD_TOKEN;
    // Africa's Talking sends every answer so far, joined by "*".
    $this->dial = function (string $session, array $answers, string $phone = '+27825550101') {
        $out = [];
        for ($i = 0; $i <= count($answers); $i++) {
            $out[] = $this->post($this->url, ['sessionId' => $session, 'phoneNumber' => $phone, 'serviceCode' => '*384*1234#', 'text' => implode('*', array_slice($answers, 0, $i))])
                ->assertOk()->getContent();
        }

        return $out;
    };
});

it('books a free time from a basic phone, with a confirmation, and charges the business once', function () {
    $screens = ($this->dial)('ATUid_1', [$this->number, '1', '1', '1', '1', 'Thandi Mokoena', '1']);

    expect($screens[0])->toBe("CON WURK bookings\nEnter the business's number:")
        ->and($screens[1])->toBe("CON Glow Salon\n1 Book\n2 My bookings\n0 Exit")
        ->and($screens[2])->toBe("CON Choose a service:\n1 Knotless braids R450.00\n0 Back")
        ->and($screens[3])->toBe("CON Knotless braids\nChoose a day:\n1 Mon 3 May\n2 Mon 10 May\n0 Back")
        ->and($screens[4])->toBe("CON Mon 3 May\nChoose a time:\n1 09:00\n2 09:15\n3 09:30\n4 09:45\n5 10:00\n0 Back")
        ->and($screens[5])->toBe('CON Your name for the booking:')
        ->and($screens[6])->toBe("CON Book Knotless braids, Mon 3 May 09:00, R450.00?\n1 Yes\n2 No")
        ->and($screens[7])->toBe('END Booked: Knotless braids, Mon 3 May 09:00. A confirmation is on its way.');

    $booking = ($this->in)(fn () => Booking::query()->sole());
    expect([$booking->customer_name, $booking->customer_phone, $booking->source, $booking->status])->toBe(['Thandi Mokoena', '+27825550101', 'ussd', 'confirmed'])
        ->and(($this->in)(fn () => app(CreditWallet::class)->balance()['balance_milli']))->toBe(10000 - 2000);
    expect(UssdSession::query()->find('ATUid_1'))->ended->toBeTrue()->steps->toBe(8);
    foreach ($screens as $s) {
        expect(mb_strlen($s) - 4)->toBeLessThanOrEqual(Screen::MAX);
    }
});

it('takes the business number dialled in the code, and lets the caller see, cancel and move their own bookings', function () {
    ($this->dial)('ATUid_2', [$this->number, '1', '1', '1', '1', 'Thandi', '1']);
    ($this->dial)('ATUid_3', [$this->number, '1', '1', '1', '1', 'Sipho', '1'], '+27825550199'); // someone else's

    // *384*1234*<number>#: straight to the business
    $first = $this->post($this->url, ['sessionId' => 'ATUid_4', 'phoneNumber' => '+27825550101', 'serviceCode' => '*384*1234#', 'text' => $this->number])->getContent();
    expect($first)->toBe("CON Glow Salon\n1 Book\n2 My bookings\n0 Exit");
    $mine = $this->post($this->url, ['sessionId' => 'ATUid_4', 'phoneNumber' => '+27825550101', 'text' => $this->number.'*2'])->getContent();
    expect($mine)->toBe("CON Your bookings:\n1 Knotless braids, Mon 3 May 09:00\n0 Back"); // only Thandi's
    $move = fn (string $text) => $this->post($this->url, ['sessionId' => 'ATUid_4', 'phoneNumber' => '+27825550101', 'text' => $this->number.'*2*'.$text])->getContent();
    expect($move('1'))->toBe("CON Knotless braids, Mon 3 May 09:00\n1 Cancel it\n2 Move it\n0 Back")
        // Mon 3 May is now full (Thandi at 09:00, Sipho at 10:00): only Mon 10 May is offered.
        ->and($move('1*2'))->toBe("CON Knotless braids\nChoose a day:\n1 Mon 10 May\n0 Back")
        ->and($move('1*2*1'))->toBe("CON Mon 10 May\nChoose a time:\n1 09:00\n2 09:15\n3 09:30\n4 09:45\n5 10:00\n0 Back")
        ->and($move('1*2*1*5'))->toBe("CON Move to Mon 10 May 10:00?\n1 Yes\n2 No")
        ->and($move('1*2*1*5*1'))->toBe('END Moved to Mon 10 May 10:00. A confirmation is on its way.');

    $cancel = ($this->dial)('ATUid_5', [$this->number, '2', '1', '1', '1']);
    expect($cancel[4])->toBe("CON Cancel Knotless braids, Mon 10 May 10:00?\n1 Yes, cancel\n2 No")
        ->and($cancel[5])->toBe('END Cancelled: Knotless braids, Mon 10 May 10:00. A confirmation is on its way.');
    expect(($this->in)(fn () => Booking::query()->orderBy('customer_name')->get(['customer_name', 'status'])->toArray()))
        ->toBe([['customer_name' => 'Sipho', 'status' => 'confirmed'], ['customer_name' => 'Thandi', 'status' => 'cancelled']]);
});

it('offers to carry on a dropped session for two minutes', function () {
    ($this->dial)('ATUid_6', [$this->number, '1', '1']); // dropped while choosing a day
    $this->travel(60)->seconds();
    $again = ($this->dial)('ATUid_7', ['1']);
    expect($again[0])->toBe("CON You were booking with Glow Salon. Carry on?\n1 Yes\n2 Start again")
        ->and($again[1])->toBe("CON Knotless braids\nChoose a day:\n1 Mon 3 May\n2 Mon 10 May\n0 Back");
    expect(($this->in)(fn () => app(CreditWallet::class)->balance()['balance_milli']))->toBe(10000 - 2000); // charged once

    ($this->dial)('ATUid_8', [$this->number, '1']);
    $this->travel(3)->minutes();
    expect(($this->dial)('ATUid_9', [])[0])->toBe("CON WURK bookings\nEnter the business's number:"); // too late to carry on
});

it('refuses unknown numbers, businesses without USSD or credits, wrong tokens, and pages long lists', function () {
    expect(($this->dial)('ATUid_10', ['9999999'])[1])->toBe("CON No business has that number.\nEnter the business's number:");
    $this->post('http://'.config('wurk.domains.app').'/webhooks/ussd/africastalking/wrong-token-0000000', ['sessionId' => 'x', 'phoneNumber' => '+27825550101', 'text' => ''])->assertNotFound();

    ($this->in)(function () {
        foreach (range(1, 9) as $n) {
            $s = BookingService::query()->create(['name' => "Service number {$n} with a long name", 'duration_minutes' => 30, 'price_cents' => 10000 + $n]);
            DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $s->id, 'member_id' => USSD_STYLIST, 'tenant_id' => app(TenantContext::class)->id()]);
        }
    });
    $list = ($this->dial)('ATUid_11', [$this->number, '1', '8']);
    expect($list[2])->toContain("\n7 ")->toContain('8 More  0 Back')->and(mb_strlen($list[2]) - 4)->toBeLessThanOrEqual(Screen::MAX)
        ->and($list[3])->toContain("\n1 ")->toContain('0 Back')->not->toContain('8 More');

    ($this->in)(fn () => CreditBalance::query()->whereKey($this->glow->id)->update(['balance_milli' => 0, 'allowance_milli' => 0]));
    expect(($this->dial)('ATUid_12', [$this->number], '+27825550177')[1])->toBe('END Sorry, Glow Salon can’t take bookings by USSD right now. Please call them.');
    ReachNumber::query()->whereKey($this->glow->id)->update(['ussd_on' => false]);
    expect(($this->dial)('ATUid_13', [$this->number], '+27825550178')[1])->toBe('END Glow Salon doesn’t take bookings by USSD. Please call them.');
});
