<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Messaging\Mail\CustomerMessageMail;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Support\ChatBot;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->owner = $this->person(['email' => 'thandi@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->glow, $fn);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Thandi', 'owner'));
    $this->base = $this->app('/glow-salon/settings/messages');
});

it('lists every customer message with its channels, and saves, resets and test-sends', function () {
    $this->actingAs($this->owner)->get($this->base)->assertInertia(function (Assert $p) {
        $p->component('Messaging::Messages');
        $m = collect($p->toArray()['props']['messages'])->keyBy('key');
        expect(array_column($m['booking.confirmed']['channels'], 'channel'))->toBe(['email', 'sms'])
            ->and($m['booking.confirmed']['whatsapp'])->toBeTrue()
            ->and(array_column($m['order.paid']['channels'], 'channel'))->toBe(['email'])
            ->and($m['reply.received']['optional'])->toBeTrue()->and($m['reply.received']['enabled'])->toBeFalse();
    });
    $this->actingAs($this->owner)->put("{$this->base}/booking.confirmed/email", ['subject' => 'See you, {client}!', 'text' => 'Booked: {service} on {when}. {link}'])->assertSessionHas('status', 'Message saved.');
    $this->actingAs($this->owner)->put("{$this->base}/booking.confirmed/sms", ['text' => 'No link here'])->assertSessionHasErrors(['text' => 'Keep {link} — customers need it.']);

    $this->actingAs($this->owner)->post("{$this->base}/booking.confirmed/email/test")->assertSessionHas('status', 'Test email sent to thandi@glow.co.za.');
    Mail::assertSent(CustomerMessageMail::class, fn ($m) => $m->hasTo('thandi@glow.co.za') && $m->subjectLine === '[Test] See you, Thandi!' && $m->body === 'Booked: Knotless braids on Sat 3 May at 10:00. https://glow-salon.wurk.co.za/b/abc123');

    $this->actingAs($this->owner)->delete("{$this->base}/booking.confirmed/email")->assertSessionHas('status', 'Back to the standard wording.');
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->where('messages.0.channels.0.own', false));
});

it('sends the optional auto-reply only once switched on, and only to an email address', function () {
    ($this->in)(fn () => app(ChatBot::class)->autoReply('sipho@example.com', 'Sipho', 'Glow Salon'));
    Mail::assertNothingSent(); // off by default

    $this->actingAs($this->owner)->put("{$this->base}/reply.received", ['enabled' => true])->assertSessionHas('status', 'Switched on.');
    ($this->in)(fn () => app(ChatBot::class)->autoReply(null, 'Phone-only', 'Glow Salon')); // no email: nothing
    ($this->in)(fn () => app(ChatBot::class)->autoReply('sipho@example.com', 'Sipho', 'Glow Salon'));
    Mail::assertSent(CustomerMessageMail::class, 1);
    Mail::assertSent(CustomerMessageMail::class, fn ($m) => $m->hasTo('sipho@example.com') && $m->subjectLine === 'We got your message — Glow Salon'
        && $m->body === 'Hi Sipho, thanks for getting in touch with Glow Salon. We’ll get back to you as soon as we can.');
    $this->actingAs($this->owner)->put("{$this->base}/booking.confirmed", ['enabled' => true])->assertNotFound(); // only optional messages switch
});
