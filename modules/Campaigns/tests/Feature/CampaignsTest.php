<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Campaigns\Mail\CampaignMail;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Crm\Contracts\Audiences;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\SendResult;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'marketing'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->sms = new class implements Messenger
    {
        public array $sent = [];

        public function sms(string $to, string $text, string $purpose): SendResult
        {
            $this->sent[] = [$to, $text];

            return SendResult::sent('test');
        }

        public function whatsappTemplate(string $to, string $template, array $parameters, string $purpose, string $language = 'en'): SendResult
        {
            return SendResult::sent('test');
        }

        public function channelActive(string $channel): bool
        {
            return true;
        }
    };
    app()->instance(Messenger::class, $this->sms);
    [$this->thandi, $this->sipho, $this->lerato] = $this->ctx->run($this->glow, function () {
        $book = app(ContactBook::class);
        $thandi = $book->remember('Thandi Mokoena', '082 000 0001', 'thandi@example.com', 'order', 'o1', null, 'Order', 'checkout');
        $sipho = $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'order', 'o2', null, 'Order', 'checkout');
        $lerato = $book->remember('Lerato Dube', '082 000 0003', 'lerato@example.com', 'order', 'o3', null, 'Order'); // no consent
        DB::connection($this->ctx->connectionName())->table('contact_links')->where('contact_id', $sipho)->update(['occurred_at' => now()->subDays(90)]);

        return [$thandi, $sipho, $lerato];
    });
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    foreach (['owner' => 'owner', 'staff' => 'staff'] as $who => $role) {
        $this->member($this->$who, $this->glow);
        $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->$who->id, ucfirst($who), $role));
    }
    $this->base = $this->app('/glow-salon/campaigns');
    $this->site = 'http://glow-salon.'.config('wurk.domains.root');
    $this->make = fn (array $over = []) => $this->actingAs($this->owner)->post($this->base.'/new', [...['name' => 'Win back', 'channel' => 'email', 'segment' => 'inactive',
        'subject' => 'We miss you, {first_name}', 'body' => "Hi {first_name}, come back: https://example.com/book\nSee you!"], ...$over]);
    $this->latest = fn () => $this->ctx->run($this->glow, fn () => (string) DB::connection($this->ctx->connectionName())->table('campaigns')->orderByDesc('created_at')->value('id'));
});

it('reaches only customers who agreed, by audience, with an estimate first', function () {
    $est = fn (string $q) => $this->actingAs($this->owner)->getJson($this->base.'/estimate?'.$q)->json();
    expect($est('channel=email&segment=all')['recipients'])->toBe(2)            // Lerato never
        ->and($est('channel=email&segment=inactive')['recipients'])->toBe(1)      // Sipho
        ->and($est('channel=email&segment=recent')['recipients'])->toBe(1)        // Thandi
        ->and($est('channel=sms&segment=all'))->toMatchArray(['recipients' => 1, 'enough' => false]); // Thandi's phone; no credits yet
    $this->actingAs($this->staff)->get($this->base)->assertNotFound();
});

it('sends a personalised email with tracked links, an open image and an opt-out — which withdraws consent', function () {
    ($this->make)()->assertSessionHas('status');
    $id = ($this->latest)();
    $this->actingAs($this->owner)->post($this->base."/{$id}/test")->assertSessionHas('status', 'Test sent to owner@glow.co.za.');
    $this->actingAs($this->owner)->post($this->base."/{$id}/send", ['when' => 'now'])->assertSessionHas('status', 'Sent to 1 customer.');
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('owner@glow.co.za') && str_starts_with($m->subjectLine, '[Test] We miss you, ') && ! str_contains($m->subjectLine, '{first_name}'));
    $mail = null;
    Mail::assertSent(CampaignMail::class, function (CampaignMail $m) use (&$mail) {
        if ($m->hasTo('sipho@example.com')) {
            $mail = $m;
        }

        return $m->hasTo('sipho@example.com') && $m->subjectLine === 'We miss you, Sipho' && str_starts_with($m->text, 'Hi Sipho, come back: '.$this->site.'/c/');
    });
    Mail::assertNotSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('thandi@example.com') || $m->hasTo('lerato@example.com'));
    preg_match('#'.preg_quote($this->site, '#').'/c/[a-z0-9]{32}/l\?u=[^\s]+#', $mail->text, $link);
    $this->get($link[0])->assertRedirect('https://example.com/book');
    $this->get(preg_replace('/s=[a-f0-9]+/', 's=000', $link[0]))->assertRedirect('https://glow-salon.'.config('wurk.domains.root').'/'); // tampered: home
    $this->get($mail->openUrl)->assertOk()->assertHeader('Content-Type', 'image/gif');
    $this->ctx->run($this->glow, function () {
        $m = DB::connection($this->ctx->connectionName())->table('campaign_messages')->sole();
        expect($m->status)->toBe('sent')->and($m->clicked_at)->not->toBeNull()->and($m->opened_at)->not->toBeNull();
    });
    $this->post($mail->stopUrl)->assertOk()->assertSee('You’re unsubscribed');
    expect($this->ctx->run($this->glow, fn () => app(Audiences::class)->hasConsent($this->sipho)))->toBeFalse();
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->where('campaigns.0', fn ($c) => $c['sent'] === 1 && $c['opened'] === 1 && $c['clicked'] === 1));
    $this->actingAs($this->owner)->post($this->base."/{$id}/send", ['when' => 'now'])->assertSessionHasErrors(['send' => 'This campaign has already been sent.']);
});

it('refuses SMS the credits won’t cover, sends it once topped up, and sends scheduled campaigns when due', function () {
    ($this->make)(['channel' => 'sms', 'segment' => 'all', 'subject' => null, 'body' => 'Hi {first_name}, 10% off this week!']);
    $id = ($this->latest)();
    $this->actingAs($this->owner)->post($this->base."/{$id}/send", ['when' => 'now'])->assertSessionHasErrors('send');
    expect($this->sms->sent)->toBe([]);
    $this->ctx->run($this->glow, fn () => app(CreditWallet::class)->grant('purchase', 50000, 'test-topup', 'Test top-up'));
    $this->actingAs($this->owner)->post($this->base."/{$id}/send", ['when' => 'now'])->assertSessionHas('status', 'Sent to 1 customer.');
    $this->actingAs($this->owner)->post($this->base."/{$id}/send", ['when' => 'later', 'at' => now()->addHour()->format('Y-m-d\TH:i')])
        ->assertSessionHasErrors(['send' => 'This campaign has already been sent.']); // can't reschedule a sent one
    expect($this->sms->sent[0][0])->toBe('+27820000001')->and($this->sms->sent[0][1])->toStartWith('Hi Thandi, 10% off this week! Stop: '.$this->site.'/c/');

    ($this->make)(['name' => 'Later']);
    $later = $this->ctx->run($this->glow, fn () => (string) DB::connection($this->ctx->connectionName())->table('campaigns')->where('name', 'Later')->value('id')); // (same second as the SMS one)
    $this->actingAs($this->owner)->post($this->base."/{$later}/send", ['when' => 'later', 'at' => now()->addHour()->format('Y-m-d\TH:i')])->assertSessionHas('status', 'Scheduled.');
    $this->artisan('campaigns:send-due');
    Mail::assertNotSent(CampaignMail::class);
    $this->travel(2)->hours();
    $this->artisan('campaigns:send-due');
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $m) => $m->hasTo('sipho@example.com'));
});
