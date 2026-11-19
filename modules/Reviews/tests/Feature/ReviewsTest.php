<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Messaging\Events\WhatsAppReceived;
use Wurk\Platform\Contracts\FinishedVisits;
use Wurk\Reviews\Models\Review;
use Wurk\Reviews\Models\ReviewRequest;
use Wurk\Reviews\Support\Reviews;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 25.3a: after a visit or an order, one message asks "How was it?" (2–26 hours later, at most once per
 * customer per 90 days, never after STOP). Everyone who answers sees the same Google link and the same private word
 * with the owner — no gating. The owner reads, replies, and hides only for a stated reason (still counted).
 */

uses(InteractsWithShards::class, InteractsWithIdentity::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-03 18:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->glow, $fn);
    ($this->in)(fn () => app(CreditWallet::class)->grant('purchase', 100000, 'buy:1', 'Purchase'));
    $this->visits = [];
    app(FinishedVisits::class)->register('test', fn (DateTimeInterface $from, DateTimeInterface $until) => array_values(array_filter($this->visits,
        fn (array $v) => CarbonImmutable::parse($v['finished_at'])->getTimestamp() >= $from->getTimestamp() && CarbonImmutable::parse($v['finished_at'])->getTimestamp() < $until->getTimestamp())));
    $this->visit = fn (string $key, string $phone, int $hoursAgo, string $title = 'Knotless braids', string $name = 'Thandi Mokoena') => $this->visits[] = [
        'key' => $key, 'kind' => 'booking', 'title' => $title, 'customer' => $name, 'phone' => $phone, 'email' => null,
        'finished_at' => now()->subHours($hoursAgo)->utc()->toIso8601String()];
    $this->messenger = new class implements Messenger
    {
        /** @var list<array{string, string}> */
        public array $sms = [];

        public function sms(string $to, string $text, string $purpose): SendResult
        {
            $this->sms[] = [$to, $text];

            return SendResult::sent('sms-1');
        }

        public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
        {
            return SendResult::failed('no');
        }

        public function channelActive(string $channel): bool
        {
            return $channel === 'sms';
        }
    };
    $this->app->instance(Messenger::class, $this->messenger);
});

it('asks once, 2–26 hours after the visit, by SMS, at most once per customer per 90 days, and never after STOP', function () {
    ($this->visit)('booking:1', '082 555 0101', 3);
    ($this->visit)('booking:2', '082 555 0202', 1);   // too soon
    ($this->visit)('booking:3', '082 555 0303', 30);  // too late
    ($this->visit)('booking:4', '+27825550101', 5, 'Colour'); // same customer, another visit
    ($this->visit)('booking:5', '082 555 0505', 4);
    ($this->in)(fn () => app(Reviews::class)->stop('0825550505'));

    expect(($this->in)(fn () => app(Reviews::class)->askDue()))->toBe(1);
    expect($this->messenger->sms)->toHaveCount(1)->and($this->messenger->sms[0][0])->toBe('+27825550101')
        ->and($this->messenger->sms[0][1])->toStartWith('Hi Thandi, thank you for choosing Glow Salon. How was your Colour? It takes a few seconds: http://glow-salon.') // the earlier of Thandi's two visits
        ->and($this->messenger->sms[0][1])->toEndWith('Reply STOP to get no more of these.');
    expect(($this->in)(fn () => app(Reviews::class)->askDue()))->toBe(0); // never twice

    // STOP on WhatsApp is respected too.
    ($this->in)(fn () => event(new WhatsAppReceived($this->glow->id, '27825550777', null, 'wamid.1', 'text', 'stop', null, null, null, new DateTimeImmutable)));
    ($this->visit)('booking:6', '082 555 0777', 3);
    expect(($this->in)(fn () => app(Reviews::class)->askDue()))->toBe(0);

    ($this->in)(fn () => app(Reviews::class)->saveSettings(false, null, null));
    ($this->visit)('booking:7', '082 555 0888', 3);
    expect(($this->in)(fn () => app(Reviews::class)->askDue()))->toBe(0); // switched off
});

it('offers everyone the same Google link and private word, whatever they rate — no gating', function () {
    ($this->in)(fn () => app(Reviews::class)->saveSettings(true, 'https://g.page/r/glow/review', null));
    ($this->visit)('booking:1', '082 555 0101', 3);
    ($this->visit)('booking:2', '082 555 0202', 3, 'Colour', 'Sipho Dlamini');
    ($this->in)(fn () => app(Reviews::class)->askDue());
    [$happy, $unhappy] = ($this->in)(fn () => ReviewRequest::query()->orderBy('visit_key')->get()->map(fn ($r) => app(Reviews::class)->link($r))->all());

    $this->get($happy)->assertOk()->assertSee('How was your Knotless braids?')->assertSee('Only the owner sees this.');
    $post = fn (string $link, array $data) => $this->post(str_replace('/review/', '/review/', ($this->in)(fn () => URL::temporarySignedRoute('reviews.public.store', now()->addDay(),
        ['handle' => 'glow-salon', 'request' => explode('?', basename($link))[0]]))), $data);

    $post($unhappy, ['rating' => 9])->assertStatus(422)->assertSee('Choose from 1 to 5 stars.');
    $a = $post($happy, ['rating' => 5, 'comment' => 'Beautiful braids, so neat!']);
    $b = $post($unhappy, ['rating' => 2, 'comment' => 'Waited 40 minutes.', 'private_note' => 'Nobody told me about the delay.']);
    foreach ([$a, $b] as $thanks) {
        $thanks->assertOk()->assertSee('Thank you')->assertSee('https://g.page/r/glow/review')->assertSee('Review us on Google');
    }
    $b->assertSee('The owner will read what you said privately.');
    $post($happy, ['rating' => 1])->assertOk()->assertSee('Thank you'); // a second answer changes nothing
    expect(($this->in)(fn () => Review::query()->orderBy('rating')->get(['name', 'rating', 'private_note'])->toArray()))->toBe([
        ['name' => 'Sipho D.', 'rating' => 2, 'private_note' => 'Nobody told me about the delay.'],
        ['name' => 'Thandi M.', 'rating' => 5, 'private_note' => null],
    ]);
    $this->get(explode('?', $happy)[0])->assertForbidden(); // signed links only
});

it('shows the owner every review with low unanswered ones first, replies, and hides only for a reason — still counted', function () {
    $owner = $this->person();
    $this->member($owner, $this->glow);
    ($this->in)(fn () => app(Team::class)->add($owner->id, 'Zodwa', 'owner'));
    [$five, $two] = ($this->in)(function () {
        $five = Review::query()->create(['name' => 'Thandi M.', 'title' => 'Knotless braids', 'rating' => 5, 'comment' => 'Lovely']);
        $this->travel(1)->minutes();
        $two = Review::query()->create(['name' => 'Sipho D.', 'title' => 'Colour', 'rating' => 2, 'comment' => 'Too slow', 'private_note' => 'Please call me']);

        return [$five, $two];
    });
    $page = $this->app('/glow-salon/reviews');
    $this->actingAs($owner)->get($page)->assertInertia(fn (Assert $p) => $p->component('Reviews::Index')
        ->where('summary', ['count' => 2, 'average' => 3.5])->where('needsReply', 1)->where('reviews.0.name', 'Sipho D.')->where('reviews.0.private_note', 'Please call me'));

    $this->actingAs($owner)->put("{$page}/{$two->id}/reply", ['reply' => 'Sorry, Sipho — we’ve added a second colourist.'])->assertSessionHas('status', 'Reply saved.');
    $this->actingAs($owner)->put("{$page}/{$five->id}/hide", ['reason' => 'too_good'])->assertSessionHasErrors('reason');
    $this->actingAs($owner)->put("{$page}/{$two->id}/hide", ['reason' => 'abusive'])->assertSessionHas('status', 'Hidden from your website. It still counts in your average.');

    $summary = ($this->in)(fn () => app(Reviews::class)->summary());
    expect($summary['count'])->toBe(2)->and($summary['average'])->toBe(3.5)->and(array_column($summary['reviews'], 'name'))->toBe(['Thandi M.']);
    expect(($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('audit_events')->where('action', 'reviews.hidden')->value('subject_id')))->toBe($two->id);

    $this->actingAs($owner)->put("{$page}/settings", ['requests_on' => true, 'google_url' => 'https://example.com/fake'])->assertSessionHasErrors('google_url');
    $this->actingAs($owner)->put("{$page}/settings", ['requests_on' => true, 'google_url' => 'https://g.page/r/glow/review'])->assertSessionHas('status', 'Saved.');
});

it('asks about real appointments once their time has passed — not cancelled ones or no-shows', function () {
    ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000]);
        foreach ([['Thandi Mokoena', '0825550101', 'confirmed'], ['Sipho Dlamini', '0825550202', 'cancelled'], ['Lerato Molefe', '0825550303', 'no_show']] as $i => [$name, $phone, $status]) {
            $at = now()->subHours(5)->addMinutes($i);
            Booking::query()->create(['service_id' => $service->id, 'member_id' => (string) Str::ulid(), 'starts_at' => $at, 'ends_at' => $at->copy()->addHour(),
                'status' => $status, 'customer_name' => $name, 'customer_phone' => $phone, 'source' => 'website']);
        }
    });
    $this->visits = [];
    expect(($this->in)(fn () => app(Reviews::class)->askDue()))->toBe(1)
        ->and($this->messenger->sms[0][1])->toContain('How was your Knotless braids?');
});
