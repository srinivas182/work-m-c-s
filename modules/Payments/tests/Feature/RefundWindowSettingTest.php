<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Payments\Contracts\RefundWindow;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1); // both businesses on the same shard
    $this->glow = $this->createTenant('glow-salon');
    $this->kasi = $this->createTenant('kasi-threads');
    $this->ctx = app(TenantContext::class);
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
    $this->url = $this->app('/glow-salon/settings/payments');
});

it('starts at 14 days, saves the business’s own window (0 = no refunds; logged), and refuses nonsense in plain words', function () {
    $this->actingAs($this->owner)->get($this->url)->assertInertia(fn (Assert $p) => $p->where('refundDays', 14));
    $this->actingAs($this->owner)->put("{$this->url}/refunds", ['days' => 30])->assertSessionHas('status', 'Saved — refunds and returns within 30 days.');
    $this->actingAs($this->owner)->put("{$this->url}/refunds", ['days' => 400])->assertSessionHasErrors(['days' => 'Up to a year (365 days).']);
    expect($this->ctx->run($this->glow, fn () => app(RefundWindow::class)->days()))->toBe(30)
        ->and($this->ctx->run($this->kasi, fn () => app(RefundWindow::class)->days()))->toBe(14); // another business on the same shard
    $this->actingAs($this->owner)->put("{$this->url}/refunds", ['days' => 0])->assertSessionHas('status', 'Saved — no refunds.');
    expect($this->ctx->run($this->glow, fn () => app(AuditEvent::class)::query()->where('action', 'payments.refund_window')->count()))->toBe(2);
});
