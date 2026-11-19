<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Payments\Support\ReturnWindow;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\ReturnsPolicy;

/*
 * Sprint 25.5: the returns policy page, written from the country pack and the owner's choices — the business's own
 * return window and what it offers, and in South Africa the customer's rights under the CPA and the ECT Act. It is a
 * draft until published, says it isn't legal advice, and the owner confirms they'll read it first.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $pkg = app(PackageCatalog::class)->create([
        'key' => 'salon', 'name' => 'Salon', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $pkg->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Zodwa', 'owner'));
    $this->base = $this->app('/glow-salon/website');
    $this->text = fn () => ($this->in)(fn () => implode("\n", array_map(fn (array $b) => ($b['props']['heading'] ?? '')."\n".($b['props']['text'] ?? ''),
        json_decode((string) Page::query()->where('slug', 'returns')->value('draft'), true)['blocks'])));
});

it('writes a South African policy with the business’s window, its offers and the CPA rights, as a draft the owner confirms', function () {
    ($this->in)(fn () => app(ReturnWindow::class)->set(30));
    $this->actingAs($this->owner)->get($this->base.'/pages')->assertInertia(fn (Assert $p) => $p
        ->where('returns', ['template' => 'za-cpa', 'days' => 30, 'page_id' => null]));

    $this->actingAs($this->owner)->post($this->base.'/returns-policy', ['offers' => ['refund', 'credit']])->assertSessionHasErrors('confirmed');
    $this->actingAs($this->owner)->post($this->base.'/returns-policy', ['offers' => ['refund', 'credit'], 'confirmed' => true])
        ->assertRedirect()->assertSessionHas('status', 'Your returns policy is ready to check. Publish it when you’re happy.');

    $page = ($this->in)(fn () => Page::query()->where('slug', 'returns')->firstOrFail());
    expect($page->published_version_id)->toBeNull(); // a draft until the owner publishes
    $text = ($this->text)();
    expect($text)->toContain('within 30 days of buying them')->toContain('You can choose a refund or store credit.')
        ->toContain('Store credit is only ever your choice')
        ->toContain('up to 6 months')->toContain('section 56')->toContain('10 business days')->toContain('section 55(3)')
        ->toContain('5 business days')->toContain('section 16')->toContain('within 7 days of delivery')->toContain('Electronic Communications and Transactions Act')
        ->toContain('Glow Salon')->not->toContain('{{business}}')->toContain('It is not legal advice.');

    // Rewriting replaces the draft of the same page.
    ($this->in)(fn () => app(ReturnWindow::class)->set(0));
    $this->actingAs($this->owner)->post($this->base.'/returns-policy', ['confirmed' => true])->assertSessionHasNoErrors();
    expect(($this->in)(fn () => Page::query()->where('slug', 'returns')->count()))->toBe(1)
        ->and(($this->text)())->toContain('We don’t take back goods you’ve simply changed your mind about.')->toContain('section 56');
});

it('writes a general policy outside South Africa, and asks what is offered when returns are taken', function () {
    $this->glow->update(['country' => 'KE']);
    ($this->in)(function () {
        expect(fn () => app(ReturnsPolicy::class)->write([], true, null))->toThrow(ValidationException::class);
        app(ReturnsPolicy::class)->write(['exchange'], true, null);
    });

    expect(($this->text)())->toContain('within 14 days')->toContain('You can choose an exchange.')
        ->toContain('doesn’t take away any right the consumer law of your country gives you')->not->toContain('Consumer Protection Act');
});
