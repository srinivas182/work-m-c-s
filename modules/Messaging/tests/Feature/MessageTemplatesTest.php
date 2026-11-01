<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Messaging\Support\Templates;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->other = $this->createTenant('kasi-threads');
    $this->in = fn ($t, callable $fn) => app(TenantContext::class)->run($t, $fn);
    $this->vars = ['client' => 'Thandi', 'service' => 'Braids', 'business' => 'Glow Salon', 'when' => 'Sat 3 May at 10:00', 'link' => 'https://x/b/1'];
    $this->problem = function (callable $fn): array {
        try {
            $fn();
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }

        return [];
    };
});

it('uses today’s exact wording until a business rewords it — per channel — and can go back', function () {
    $t = app(MessageTemplates::class);
    $standard = 'Hi Thandi, your Braids booking at Glow Salon is confirmed for Sat 3 May at 10:00. Change or cancel: https://x/b/1';
    ($this->in)($this->glow, fn () => expect($t->text('booking.confirmed', 'sms', $this->vars))->toBe($standard)
        ->and($t->subject('booking.confirmed', $this->vars))->toBe('Booking confirmed — Glow Salon'));

    ($this->in)($this->glow, fn () => app(Templates::class)->save('booking.confirmed', 'email', 'See you soon, {client}!', 'Hi {client}! You’re booked for {service} on {when}. Need to change? {link}', 'Thandi M'));
    ($this->in)($this->glow, fn () => expect($t->subject('booking.confirmed', $this->vars))->toBe('See you soon, Thandi!')
        ->and($t->text('booking.confirmed', 'email', $this->vars))->toBe('Hi Thandi! You’re booked for Braids on Sat 3 May at 10:00. Need to change? https://x/b/1')
        ->and($t->text('booking.confirmed', 'sms', $this->vars))->toBe($standard)); // SMS untouched
    ($this->in)($this->other, fn () => expect($t->subject('booking.confirmed', $this->vars))->toBe('Booking confirmed — Glow Salon')); // another business: standard

    ($this->in)($this->glow, fn () => app(Templates::class)->reset('booking.confirmed', 'email'));
    ($this->in)($this->glow, fn () => expect($t->subject('booking.confirmed', $this->vars))->toBe('Booking confirmed — Glow Salon'));
});

it('keeps rewording inside the safety rails', function () {
    $save = fn (string $channel, ?string $subject, string $text) => fn () => ($this->in)($this->glow, fn () => app(Templates::class)->save('booking.confirmed', $channel, $subject, $text, 'Thandi'));
    expect(($this->problem)($save('sms', null, 'Hi {name}, see you {when}. {link}')))->toBe(['text' => 'Unknown placeholder: {name}. You can use {client}, {service}, {business}, {when}, {link}.'])
        ->and(($this->problem)($save('sms', null, 'Hi {client}, see you {when}.')))->toBe(['text' => 'Keep {link} — customers need it.'])
        ->and(($this->problem)($save('email', 'Hi', '<b>Hi</b> {client} {link}')))->toBe(['text' => 'Plain text only — no HTML.'])
        ->and(($this->problem)($save('sms', null, str_repeat('a', 300).' {link}')))->toBe(['text' => 'Too long for two SMS parts (306 characters).'])
        ->and(($this->problem)($save('whatsapp', null, 'Hi {client} {link}')))->toBe(['channel' => 'Only email and SMS can be reworded — WhatsApp uses Meta-approved wording.'])
        ->and(($this->problem)($save('email', '', 'Hi {client} {link}')))->toBe(['subject' => 'Give the email a subject (up to 120 characters).']);
    // “Cancelled” has no link to keep.
    expect(($this->problem)(fn () => ($this->in)($this->glow, fn () => app(Templates::class)->save('booking.cancelled', 'sms', null, 'Sorry {client}, {service} on {when} is off.', 'Thandi'))))->toBe([]);
});

it('words order emails exactly as before, and keeps them email-only', function () {
    $t = app(MessageTemplates::class);
    $vars = ['business' => 'Kasi Threads', 'number' => '1042', 'amount' => 'R250.00'];
    ($this->in)($this->glow, fn () => expect($t->subject('order.placed', $vars))->toBe('Your order #1042 — Kasi Threads')
        ->and($t->text('order.placed', 'email', $vars))->toBe('Thank you for your order with Kasi Threads.')
        ->and($t->subject('order.paid', $vars))->toBe('Payment received — order #1042 · Kasi Threads')
        ->and($t->text('order.refunded', 'email', $vars))->toBe('We’ve refunded R250.00 for your order #1042.'));
    expect(($this->problem)(fn () => ($this->in)($this->glow, fn () => app(Templates::class)->save('order.paid', 'sms', null, 'Paid! #{number}', 'Thandi'))))
        ->toBe(['channel' => 'This message is only sent by email.']);
});
