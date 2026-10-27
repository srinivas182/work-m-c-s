<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Bookings\Models\TimeOff;
use Wurk\Bookings\Support\MeetingLinks;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->subscribe = function (array $modules) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.count($modules), 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => $modules,
        ], null);
        app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx = app(TenantContext::class);
    $this->ownerMember = $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Zanele', 'owner'));
    $this->base = $this->app('/glow-salon/bookings');
    $this->service = ['name' => 'Knotless braids', 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 15,
        'price_cents' => 45000, 'deposit_type' => 'none', 'deposit_value' => 0, 'active' => true, 'members' => [$this->ownerMember->id]];
});

it('is only for packages that include bookings', function () {
    ($this->subscribe)(['website']);
    expect($this->actingAs($this->owner)->post("{$this->base}/services", $this->service)->status())->toBeIn([403, 404]);
});

it('saves a service in 5-minute steps, done by real team members', function () {
    ($this->subscribe)(['bookings']);
    $this->actingAs($this->owner)->post("{$this->base}/services", [...$this->service, 'duration_minutes' => 47])->assertSessionHasErrors(['duration_minutes' => 'Use steps of 5 minutes.']);
    $this->actingAs($this->owner)->post("{$this->base}/services", [...$this->service, 'members' => []])->assertSessionHasErrors(['members' => 'Choose at least one team member who does this service.']);
    $this->actingAs($this->owner)->post("{$this->base}/services", [...$this->service, 'members' => ['01J0000000000000000000NOPE']])->assertSessionHasErrors('members.0');

    $this->actingAs($this->owner)->post("{$this->base}/services", $this->service)->assertSessionHas('status', 'Service saved.');
    $this->ctx->run($this->glow, fn () => expect(BookingService::query()->sole()->only(['name', 'duration_minutes', 'buffer_after_minutes']))
        ->toBe(['name' => 'Knotless braids', 'duration_minutes' => 60, 'buffer_after_minutes' => 15]));
});

it('saves weekly hours, refusing overlaps and backwards times', function () {
    ($this->subscribe)(['bookings']);
    $url = "{$this->base}/hours/{$this->ownerMember->id}";
    $this->actingAs($this->owner)->put($url, ['hours' => [['weekday' => 0, 'starts' => '09:00', 'ends' => '13:00'], ['weekday' => 0, 'starts' => '12:00', 'ends' => '17:00']]])
        ->assertSessionHasErrors(['hours.0.starts' => 'These hours overlap other hours on the same day.']);
    $this->actingAs($this->owner)->put($url, ['hours' => [['weekday' => 1, 'starts' => '17:00', 'ends' => '09:00']]])->assertSessionHasErrors(['hours.0.ends' => 'The end must be after the start.']);

    $this->actingAs($this->owner)->put($url, ['hours' => [['weekday' => 0, 'starts' => '09:00', 'ends' => '12:00'], ['weekday' => 0, 'starts' => '13:00', 'ends' => '17:00']]])->assertSessionHas('status', 'Hours saved.');
    $this->ctx->run($this->glow, fn () => expect(BookingHours::query()->count())->toBe(2));
});

it('takes time off in local time and stores it in UTC', function () {
    ($this->subscribe)(['bookings']);
    $this->actingAs($this->owner)->post("{$this->base}/time-off", ['member_id' => null, 'starts_at' => '2027-04-27 00:00', 'ends_at' => '2027-04-28 00:00', 'reason' => 'Freedom Day'])->assertSessionHas('status');
    $this->ctx->run($this->glow, fn () => expect(TimeOff::query()->sole()->starts_at->utc()->toIso8601String())->toBe('2027-04-26T22:00:00+00:00'));
});

it('connects the bank for deposits, keeping only a masked label, and saves the policy', function () {
    ($this->subscribe)(['bookings']);
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test'], [], true, null);
    Http::fake([
        'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [['code' => '470010', 'name' => 'Capitec Bank']]]),
        'api.paystack.co/subaccount' => Http::response(['status' => true, 'data' => ['subaccount_code' => 'ACCT_new']]),
    ]);
    $this->actingAs($this->owner)->put("{$this->base}/payouts", ['bank_code' => '999', 'account_number' => '1234567890'])->assertSessionHasErrors(['bank_code' => 'Choose your bank from the list.']);
    $this->actingAs($this->owner)->put("{$this->base}/payouts", ['bank_code' => '470010', 'account_number' => '12-34'])->assertSessionHasErrors(['account_number' => 'Enter the account number using digits only.']);
    $this->actingAs($this->owner)->put("{$this->base}/payouts", ['bank_code' => '470010', 'account_number' => '1234567890'])->assertSessionHas('status', 'Deposits will be paid into Capitec Bank ••••7890.');

    $this->actingAs($this->owner)->put("{$this->base}/policy", ['hours' => 48, 'text' => ''])->assertSessionHas('status', 'Cancellation policy saved.');
    $this->ctx->run($this->glow, fn () => expect(BookingSettings::query()->sole()->only(['payout_subaccount', 'payout_label', 'cancellation_hours']))
        ->toBe(['payout_subaccount' => 'ACCT_new', 'payout_label' => 'Capitec Bank ••••7890', 'cancellation_hours' => 48]));
});

it('connects the owner’s own Google account safely, and refuses a return that wasn’t started here', function () {
    ($this->subscribe)(['bookings']);
    app(ProviderSettings::class)->save('meetings.google', 'google', ['client_id' => 'gid', 'client_secret' => 'gsecret'], [], true, null);
    $email = rtrim(strtr(base64_encode((string) json_encode(['email' => 'thandi@gmail.com'])), '+/', '-_'), '=');
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-123', 'refresh_token' => 'rt-456', 'expires_in' => 3600, 'id_token' => "h.{$email}.s"]),
        'oauth2.googleapis.com/revoke' => Http::response([]),
    ]);

    // A forged return (no matching value in this owner's session) is refused, and nothing is stored.
    $forged = Crypt::encryptString((string) json_encode(['tenant' => $this->glow->id, 'nonce' => 'attacker', 'exp' => now()->addMinutes(5)->getTimestamp()]));
    $this->actingAs($this->owner)->get($this->app('/oauth/video/google/callback').'?code=attacker-code&state='.urlencode($forged))->assertForbidden();
    $this->ctx->run($this->glow, fn () => expect(MeetingConnection::query()->count())->toBe(0));

    $to = (string) $this->actingAs($this->owner)->get("{$this->base}/video/connect/google")->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($to, PHP_URL_QUERY), $q);
    expect($to)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($q)->toMatchArray(['client_id' => 'gid', 'access_type' => 'offline', 'redirect_uri' => $this->app('/oauth/video/google/callback')])
        ->and($q['scope'])->toContain('calendar.events');

    $this->actingAs($this->owner)->get($this->app('/oauth/video/google/callback').'?code=good-code&state='.urlencode($q['state']))
        ->assertRedirect('/glow-salon/bookings/settings')->assertSessionHas('status', 'Google Meet is connected (thandi@gmail.com). Online appointments get their own link.');
    $this->ctx->run($this->glow, function () {
        $c = MeetingConnection::query()->sole();
        expect($c->access_token)->toBe('at-123')->and($c->account_label)->toBe('thandi@gmail.com')
            ->and(DB::connection(app(TenantContext::class)->connectionName())->table('meeting_connections')->value('access_token'))->not->toContain('at-123'); // encrypted at rest
    });

    $this->actingAs($this->owner)->delete("{$this->base}/video")->assertSessionHas('status', 'The video account is disconnected. Online appointments won’t get new links until you connect again.');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/revoke') && $r['token'] === 'rt-456');
    $this->ctx->run($this->glow, fn () => expect(MeetingConnection::query()->count())->toBe(0));
});

it('accepts a fixed meeting link only over https', function () {
    ($this->subscribe)(['bookings']);
    $this->actingAs($this->owner)->put("{$this->base}/video/fixed", ['url' => 'http://meet.example.com/room'])->assertSessionHasErrors(['url' => 'Enter the full meeting link, starting with https://']);
    $this->actingAs($this->owner)->put("{$this->base}/video/fixed", ['url' => 'https://meet.example.com/room'])->assertSessionHas('status', 'Your meeting link is saved. Note: every client gets the same link.');
});

it('won’t make a service online until clients have a way to join', function () {
    ($this->subscribe)(['bookings']);
    $this->actingAs($this->owner)->post("{$this->base}/services", [...$this->service, 'location_type' => 'online'])
        ->assertSessionHasErrors(['location_type' => 'Add a meeting link (under Meeting links) for everyone who does this service, or a link for the whole business, so clients can join.']);
    $this->actingAs($this->owner)->put("{$this->base}/video/fixed", ['url' => 'https://meet.example.com/room']);
    $this->actingAs($this->owner)->post("{$this->base}/services", [...$this->service, 'location_type' => 'online'])->assertSessionHas('status', 'Service saved.');
});

it('gives each team member their own meeting link, with a business default — and online services need everyone reachable', function () {
    ($this->subscribe)(['bookings']);
    [$thandi, $sipho] = $this->ctx->run($this->glow, fn () => [app(TeamDirectory::class)->members()[0]['id'], app(TeamDirectory::class)->add((string) Str::ulid(), 'Sipho', 'staff')]);
    $online = [...$this->service, 'location_type' => 'online', 'members' => [$thandi, $sipho]];

    $this->actingAs($this->owner)->put("{$this->base}/video/links", ['default' => null, 'members' => [$thandi => 'http://zoom.us/j/1']])
        ->assertSessionHasErrors(['members.'.$thandi => 'Enter the full meeting link, starting with https://']);
    $this->actingAs($this->owner)->put("{$this->base}/video/links", ['default' => null, 'members' => ['01J00000000000000000000099' => 'https://zoom.us/j/1']])
        ->assertSessionHasErrors(['members' => 'That person isn’t on your team.']);

    $this->actingAs($this->owner)->put("{$this->base}/video/links", ['default' => null, 'members' => [$thandi => 'https://zoom.us/j/111']])->assertSessionHas('status', 'Meeting links saved.');
    $this->actingAs($this->owner)->post("{$this->base}/services", $online)->assertSessionHasErrors('location_type'); // Sipho has no link, no default
    $this->actingAs($this->owner)->put("{$this->base}/video/links", ['default' => null, 'members' => [$thandi => 'https://zoom.us/j/111', $sipho => 'https://meet.google.com/abc-defg-hij']]);
    $this->actingAs($this->owner)->post("{$this->base}/services", $online)->assertSessionHas('status', 'Service saved.');

    $this->ctx->run($this->glow, function () use ($thandi, $sipho) {
        $links = app(MeetingLinks::class);
        expect($links->forMember($thandi))->toBe('https://zoom.us/j/111')->and($links->forMember($sipho))->toBe('https://meet.google.com/abc-defg-hij')
            ->and($links->forMember('01J00000000000000000000099'))->toBeNull();
    });
    $this->actingAs($this->owner)->put("{$this->base}/video/links", ['default' => 'https://meet.google.com/our-room', 'members' => [$thandi => 'https://zoom.us/j/111', $sipho => null]]);
    $this->ctx->run($this->glow, fn () => expect(app(MeetingLinks::class)->forMember($sipho))->toBe('https://meet.google.com/our-room')); // falls back to the default
});
