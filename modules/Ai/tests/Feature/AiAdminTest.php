<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Ai\Models\AiRequest;
use Wurk\Ai\Support\AiSettings;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    $this->staff = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $this->page = $this->admin('/settings/ai');
    $this->valid = AiSettings::DEFAULTS;
});

it('is only for staff allowed to manage WURK AI', function () {
    $support = $this->staffWithRole('support', 'support@wurk.co.za');
    $this->actingAs($support)->get($this->page)->assertForbidden();
    $this->actingAs($support)->putJson($this->page, $this->valid)->assertForbidden();
});

it('shows this month’s real cost per provider, including failures and fallbacks', function () {
    AiRequest::query()->create(['task' => 'site_generation', 'provider' => 'anthropic', 'model' => 'm', 'status' => 'failed', 'cost_usd_micro' => 0, 'created_at' => now()]);
    AiRequest::query()->create(['task' => 'site_generation', 'provider' => 'google', 'model' => 'm', 'status' => 'succeeded', 'fallback' => true, 'cost_usd_micro' => 500000, 'created_at' => now()]);
    AiRequest::query()->create(['task' => 'site_generation', 'provider' => 'google', 'model' => 'm', 'status' => 'succeeded', 'cost_usd_micro' => 999999, 'created_at' => now()->subMonths(2)]);

    $this->actingAs($this->staff)->get($this->page)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Ai::Admin/Ai')
        ->where('usage.totalZar', 9) // $0.50 × R18 this month only
        ->where('usage.byProvider', fn ($rows) => collect($rows)->firstWhere('provider', 'Google (Gemini)')['fallbacks'] === 1
            && collect($rows)->firstWhere('provider', 'Anthropic (Claude)')['failed'] === 1));
});

it('saves pinned models and refuses “latest” aliases and the simulated provider in production', function () {
    $this->actingAs($this->staff)->put($this->page, [...$this->valid, 'fx_zar_per_usd' => 18.4, 'target_margin_pct' => 45])->assertSessionHasNoErrors();
    expect(app(AiSettings::class)->all())->toMatchArray(['fx_zar_per_usd' => 18.4, 'target_margin_pct' => 45]);

    $latest = [...$this->valid, 'tasks' => [...$this->valid['tasks'], 'rewrite' => ['provider' => 'anthropic', 'model' => 'claude-haiku-latest']]];
    $this->actingAs($this->staff)->put($this->page, $latest)->assertSessionHasErrors('tasks.rewrite.model');

    // In "production" Laravel also enforces CSRF in tests; switch only that off, so this
    // proves the production guard itself (not a 419) refuses the simulated provider.
    app()->detectEnvironment(fn () => 'production');
    $this->withoutMiddleware(PreventRequestForgery::class);
    $simulated = [...$this->valid, 'tasks' => [...$this->valid['tasks'], 'rewrite' => ['provider' => 'simulated', 'model' => 'simulated']]];
    $this->actingAs($this->staff)->put($this->page, $simulated)->assertRedirect()->assertSessionHasErrors(['tasks' => 'The simulated provider can’t be used in production.']);
    expect(app(AiSettings::class)->all()['tasks']['rewrite']['provider'])->toBe('anthropic');
});

it('tries a model with a sample and its cost, and explains a missing key', function () {
    $this->actingAs($this->staff)->postJson($this->page.'/try', ['provider' => 'simulated', 'model' => 'simulated'])->assertOk()->assertJsonStructure(['text', 'input_tokens', 'output_tokens', 'usd']);
    $this->actingAs($this->staff)->postJson($this->page.'/try', ['provider' => 'openai', 'model' => 'gpt-6-sol'])->assertStatus(422)->assertJson(['message' => 'Add this provider’s API key under Providers first.']);
});
