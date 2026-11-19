<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Reach\Models\ReachNumber;
use Wurk\Reach\Support\ReachNumbers;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 25.2b: SMS booking (the USSD menu by SMS, one reply per step, from WURK's Africa's Talking number), "what I
 * owe", and Settings → Bookings by phone: the business's number, its own SMS word, switches and counter cards.
 */

uses(InteractsWithShards::class, InteractsWithIdentity::class);

const SMS_STYLIST = '01J00000000000000000000SMS';
const SMS_TOKEN = 'sms-secret-token-12345';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
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
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $braids->id, 'member_id' => SMS_STYLIST, 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => SMS_STYLIST, 'weekday' => 0, 'starts' => '09:00', 'ends' => '10:00']); // one time on Mondays
        app(CreditWallet::class)->grant('purchase', 100000, 'buy:1', 'Purchase');
    });
    $this->number = app(ReachNumbers::class)->for($this->glow->id)->number;
    app(ProviderSettings::class)->save('ussd', 'africastalking', ['username' => 'wurk', 'api_key' => 'at-key'],
        ['service_code' => '*384*1234#', 'webhook_token' => SMS_TOKEN, 'sms_number' => '40404'], true, null);
    $this->sent = [];
    Http::fake(['api.africastalking.com/version1/messaging' => function ($request) {
        $this->sent[] = [$request['to'], $request['message'], $request['from']];

        return Http::response(['SMSMessageData' => ['Recipients' => [['status' => 'Success']]]]);
    }]);
    $this->text = fn (string $text, string $from = '+27825550101') => $this->post('http://'.config('wurk.domains.app').'/webhooks/sms/africastalking/'.SMS_TOKEN,
        ['from' => $from, 'to' => '40404', 'text' => $text, 'id' => uniqid()])->assertOk();
    $this->last = fn () => end($this->sent)[1];
});

it('books by SMS, one reply per step from WURK’s number, charging the business per reply', function () {
    ($this->text)('book '.$this->number);
    expect(($this->last)())->toBe("Glow Salon\n1 Book\n2 My bookings\n3 What I owe\n0 Exit\nReply with a number.")
        ->and($this->sent[0][0])->toBe('+27825550101')->and($this->sent[0][2])->toBe('40404');
    foreach (['1', '1', '1', '1'] as $answer) {
        ($this->text)($answer);
    }
    expect(($this->last)())->toBe('Your name for the booking:'); // no "reply with a number" for a name
    ($this->text)('Thandi Mokoena');
    expect(($this->last)())->toBe("Book Knotless braids, Mon 3 May 09:00, R450.00?\n1 Yes\n2 No\nReply with a number.");
    ($this->text)('1');
    expect(($this->last)())->toBe('Booked: Knotless braids, Mon 3 May 09:00. A confirmation is on its way.');

    expect(($this->in)(fn () => Booking::query()->sole()->only(['customer_name', 'source', 'status'])))->toBe(['customer_name' => 'Thandi Mokoena', 'source' => 'sms', 'status' => 'confirmed'])
        ->and(($this->in)(fn () => app(CreditWallet::class)->balance()['balance_milli']))->toBe(100000 - 7 * 3000); // seven replies at the SMS rate, no USSD charge
});

it('starts with the business’s own word, asks for a number otherwise, and lets a new BOOK start over', function () {
    ReachNumber::query()->whereKey($this->glow->id)->update(['sms_keyword' => 'GLOW']);
    ($this->text)('glow', '+27825550111');
    expect(($this->last)())->toStartWith("Glow Salon\n1 Book");
    ($this->text)('hello', '+27825550122'); // a stranger with no conversation
    expect(($this->last)())->toBe("WURK bookings\nEnter the business's number:");
    ($this->text)('0000', '+27825550122');
    expect(($this->last)())->toBe("No business has that number.\nEnter the business's number:");
    ($this->text)('BOOK GLOW', '+27825550122');
    expect(($this->last)())->toStartWith("Glow Salon\n1 Book");

    ReachNumber::query()->whereKey($this->glow->id)->update(['sms_on' => false]);
    ($this->text)('GLOW', '+27825550133');
    expect(($this->last)())->toBe('Glow Salon doesn’t take bookings by SMS. Please call them.');
});

it('tells the caller what they owe here, by their number', function () {
    app(MoneyOwed::class)->register('test', fn () => [
        ['key' => 'inv:1', 'kind' => 'invoice', 'title' => 'INV-0007', 'customer' => 'Thandi', 'phone' => '082 555 0101', 'email' => null, 'amount_cents' => 138000, 'since' => '2027-04-20', 'due' => '2027-04-27', 'link' => '/x'],
        ['key' => 'inv:2', 'kind' => 'invoice', 'title' => 'INV-0008', 'customer' => 'Sipho', 'phone' => '082 555 0199', 'email' => null, 'amount_cents' => 5000, 'since' => '2027-04-20', 'due' => null, 'link' => '/y'],
    ]);
    ($this->text)('BOOK '.$this->number);
    ($this->text)('3');
    expect(($this->last)())->toBe("You owe:\nR1 380.00 INV-0007\nPay at the business, or with the link in your message.\n0 Back\nReply with a number.");
});

it('shows the business its number, how customers book, and lets the owner switch channels and choose a word', function () {
    $owner = $this->person();
    $this->member($owner, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($owner->id, 'Zodwa', 'owner'));
    $page = $this->app('/glow-salon/settings/reach');
    $this->actingAs($owner)->get($page)->assertInertia(fn (Assert $p) => $p->component('Reach::Settings')
        ->where('reach.number', $this->number)->where('how.dial', '*384*1234*'.$this->number.'#')->where('how.sms', 'Text BOOK '.$this->number.' to 40404'));

    $this->actingAs($owner)->put($page, ['ussd_on' => true, 'sms_on' => true, 'sms_keyword' => 'stop'])->assertSessionHasErrors(['sms_keyword' => 'That word is used by the SMS menu. Choose another.']);
    $other = $this->createTenant('kasi-cuts');
    ReachNumber::query()->create(['tenant_id' => $other->id, 'number' => '9999', 'sms_keyword' => 'KASI']);
    $this->actingAs($owner)->put($page, ['ussd_on' => true, 'sms_on' => true, 'sms_keyword' => 'kasi'])->assertSessionHasErrors(['sms_keyword' => 'Another business uses that word. Choose another.']);
    $this->actingAs($owner)->put($page, ['ussd_on' => false, 'sms_on' => true, 'sms_keyword' => 'glow'])->assertSessionHas('status', 'Saved.');

    expect(ReachNumber::query()->find($this->glow->id)->only(['ussd_on', 'sms_keyword']))->toBe(['ussd_on' => false, 'sms_keyword' => 'GLOW']);
    $this->actingAs($owner)->get($page)->assertInertia(fn (Assert $p) => $p->where('how.dial', null)->where('how.sms', 'Text GLOW to 40404'));
    $this->actingAs($owner)->get($page.'/card')->assertOk()->assertSee('Book from any phone')->assertSee('Text GLOW to 40404')->assertDontSee('*384*');

    $staff = $this->person(['email' => 'lindiwe@glow.co.za', 'phone' => '+27821234500']);
    $this->member($staff, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($staff->id, 'Lindiwe', 'staff'));
    $this->actingAs($staff)->get($page)->assertNotFound(); // inside a business a refusal is a 404
});

it('shows a new number’s switches as on before it’s read back (an unset switch has no state to announce)', function () {
    $other = $this->createTenant('other-salon', name: 'Other Salon');
    $fresh = app(ReachNumbers::class)->for($other->id);
    expect($fresh->ussd_on)->toBeTrue()->and($fresh->sms_on)->toBeTrue();
});
