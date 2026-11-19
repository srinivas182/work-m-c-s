<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Support\OwnerSummaries;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Shop\Models\Order;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\ReceptionistConversation;

/*
 * Sprint 21.6: the owner's evening WhatsApp summary from WURK's number, and answering its requests by replying with a
 * number. Meta's API and webhooks are faked and replayed.
 */
uses(InteractsWithIdentity::class, InteractsWithShards::class);

const OS_API = 'https://graph.facebook.com/v23.0/wurk-pn/messages';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-04 18:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->owner = $this->person(['phone' => '+27825550101']);
    $this->owner->forceFill(['phone_verified_at' => now()])->save();
    $this->member($this->owner, $this->glow);
    $this->staff = $this->person(['first_name' => 'Lindiwe', 'email' => 'lindiwe@glow.co.za', 'phone' => '+27825550102']);
    $this->staff->forceFill(['phone_verified_at' => now()])->save();
    $this->member($this->staff, $this->glow);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->glow, $fn);
    ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Thandi', 'owner');
        app(Team::class)->add($this->staff->id, 'Lindiwe', 'staff');
        app(CreditWallet::class)->grant('purchase', 100000, 'buy:1', 'Purchase');
        foreach ([[7, 45000, 'Sipho'], [8, 12000, 'Ayanda']] as [$n, $cents, $name]) {
            Order::query()->create(['number' => $n, 'status' => 'awaiting_payment', 'payment_method' => 'offline', 'fulfilment' => 'collection', 'customer_name' => $name,
                'customer_phone' => '0820000000', 'subtotal_cents' => $cents, 'total_cents' => $cents]);
        }
        ReceptionistConversation::query()->create(['channel' => 'whatsapp', 'visitor' => 'v', 'status' => 'waiting', 'phone' => '+27820000001', 'last_message_at' => now()]);
    });
    app(ProviderSettings::class)->save('whatsapp', 'meta', ['access_token' => 'wurk-token', 'app_secret' => 'app-secret'], ['phone_number_id' => 'wurk-pn'], true, null);
    $this->sent = 0;
    Http::fake([OS_API => fn () => Http::response(['messages' => [['id' => 'wamid.w'.(++$this->sent)]]])]);
    $this->base = $this->app('/glow-salon/settings/whatsapp');
    $this->toWurk = fn () => collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn ($r) => $r->url() === OS_API)->values();
    $this->reply = function (string $text, string $from = '27825550101') {
        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'wurk-waba', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'wurk-pn'], 'messages' => [['from' => $from, 'id' => 'wamid.'.uniqid(), 'type' => 'text', 'text' => ['body' => $text]]]]]]]]]);

        return $this->call('POST', $this->app('/webhooks/messaging/meta'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret')], $body)->assertOk();
    };
});

it('sends the owner’s evening summary once, at their time, to their verified number, with requests to answer', function () {
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->where('summary', ['enabled' => false, 'send_at' => '19:00', 'phone' => '+27825550101']));
    $this->actingAs($this->owner)->put("{$this->base}/summary", ['enabled' => true, 'send_at' => '19:30'])->assertSessionHas('status', 'Your evening summary is on: it comes to your WhatsApp at 19:30.');

    $this->artisan('messaging:owner-summaries')->expectsOutput('Summaries sent: 0.'); // 18:00: not yet
    $this->travelTo(CarbonImmutable::parse('2027-05-04 19:30', 'Africa/Johannesburg'));
    $this->artisan('messaging:owner-summaries')->expectsOutput('Summaries sent: 1.');
    $this->artisan('messaging:owner-summaries')->expectsOutput('Summaries sent: 0.'); // once a day

    $t = ($this->toWurk)()->sole();
    expect($t['to'])->toBe('27825550101')->and($t['template']['name'])->toBe(OwnerSummaries::TEMPLATE)
        ->and(array_column($t['template']['components'][0]['parameters'], 'text'))->toBe(['Thandi', 'Glow Salon', '0', '2', 'R0.00', '1',
            'Needs your yes or no: 1) Mark order #7 (R450.00, Sipho) as paid by EFT? · 2) Mark order #8 (R120.00, Ayanda) as paid by EFT? Reply with the number to say yes, or N and the number to say no (e.g. N1).']);
});

it('applies the owner’s "1" and "N2" as them, audited, and answers on WhatsApp', function () {
    ($this->in)(fn () => app(OwnerSummaries::class)->save($this->owner->id, true, '19:00'));
    ($this->in)(fn () => app(OwnerSummaries::class)->send($this->owner->id));

    ($this->reply)('1');
    ($this->reply)('n2');
    ($this->reply)('1'); // again
    ($this->reply)('7');
    ($this->reply)('thanks!');
    expect(($this->toWurk)()->slice(1)->map(fn ($r) => $r['text']['body'])->values()->all())->toBe([
        'Order #7 is marked paid.',
        'Order #8 is cancelled and its stock is back.',
        'You’ve already answered 1: Order #7 is marked paid.',
        'There’s no number 7 in your latest summary. '.OwnerSummaries::HELP,
        OwnerSummaries::HELP,
    ]);
    ($this->in)(function () {
        expect(Order::query()->orderBy('number')->pluck('status')->all())->toBe(['paid', 'cancelled'])
            ->and(DB::connection(app(TenantContext::class)->connectionName())->table('audit_events')->where('action', 'messaging.owner_whatsapp_answer')->count())->toBe(2);
    });
});

it('only works for the owner, from their own verified number', function () {
    ($this->in)(fn () => app(OwnerSummaries::class)->send($this->staff->id));
    expect(($this->toWurk)())->toHaveCount(0); // staff don't get it
    expect($this->actingAs($this->staff)->put("{$this->base}/summary", ['enabled' => true, 'send_at' => '19:00'])->status())->toBeIn([403, 404]);
    ($this->in)(fn () => expect(app(OwnerSummaries::class)->isOwner($this->staff->id))->toBeFalse()->and(app(OwnerSummaries::class)->isOwner($this->owner->id))->toBeTrue());

    ($this->in)(fn () => app(OwnerSummaries::class)->send($this->owner->id));
    ($this->reply)('1', '27825550102'); // someone else's number: the summary isn't answered — the help line asks for a question (25.6)
    expect(($this->toWurk)())->toHaveCount(2)->and(($this->toWurk)()->last()['text']['body'])->toBe('Ask your question in a few words, or send a voice note.');
    ($this->in)(fn () => expect(Order::query()->where('number', 7)->value('status'))->toBe('awaiting_payment'));

    $this->owner->forceFill(['phone_verified_at' => null])->save();
    ($this->reply)('1'); // the number isn't proven any more
    expect(($this->toWurk)()->last()['text']['body'])->toBe('Sorry, this number can’t answer for Glow Salon any more.');
    $this->actingAs($this->owner)->put("{$this->base}/summary", ['enabled' => true, 'send_at' => '19:00'])->assertSessionHasErrors('enabled');
});
