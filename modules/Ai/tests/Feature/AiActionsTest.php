<?php

declare(strict_types=1);

use Tests\Support\InteractsWithShards;
use Wurk\Ai\Contracts\AiActionRefused;
use Wurk\Ai\Contracts\AiActions;
use Wurk\Ai\Models\AiRequest;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

function subscribeWithModules(string $tenantId, array $modules): void
{
    $package = app(PackageCatalog::class)->create([
        'key' => 'p'.substr(md5(json_encode($modules)), 0, 6), 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => $modules,
    ], null);
    app(Catalog::class)->subscribe($tenantId, $package->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
}

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->ctx = app(TenantContext::class);
    subscribeWithModules($this->glow->id, ['website', 'ai-assistant']);
});

it('quotes exactly, with the first website free and only the first', function () {
    $this->ctx->run($this->glow, function () {
        app(CreditWallet::class)->grant('purchase', 30000, 'buy:1', 'Purchase');
        expect(app(AiActions::class)->quote('generate_site'))->toMatchArray(['credits' => 0, 'free' => true, 'balance' => 30, 'after' => 30, 'allowed' => true])
            ->and(app(AiActions::class)->quote('rewrite'))->toMatchArray(['credits' => 1, 'free' => false, 'after' => 29]);

        AiRequest::query()->create(['tenant_id' => $this->glow->id, 'task' => 'site_generation', 'provider' => 'simulated', 'model' => 'simulated', 'status' => 'succeeded', 'created_at' => now()]);
        expect(app(AiActions::class)->quote('generate_site'))->toMatchArray(['credits' => 20, 'free' => false, 'after' => 10]);
    });
});

it('refuses packages without WURK AI and balances that are too low — without running the work', function () {
    $other = $this->createTenant('no-ai');
    subscribeWithModules($other->id, ['website']);
    $ran = false;
    $this->ctx->run($other, function () use (&$ran) {
        expect(app(AiActions::class)->quote('rewrite'))->toMatchArray(['allowed' => false, 'reason' => 'not_included']);
        expect(app(AiActions::class)->quote('generate_site'))->toMatchArray(['allowed' => true, 'free' => true]); // first website: free on every package
        expect(fn () => app(AiActions::class)->perform('rewrite', 'k1', function () use (&$ran) {
            $ran = true;
        }))->toThrow(AiActionRefused::class, 'isn’t included');
    });

    $this->ctx->run($this->glow, function () use (&$ran) {
        expect(app(AiActions::class)->quote('rewrite'))->toMatchArray(['allowed' => false, 'reason' => 'insufficient', 'can_buy' => true]);
        expect(fn () => app(AiActions::class)->perform('rewrite', 'k2', function () use (&$ran) {
            $ran = true;
        }))->toThrow(AiActionRefused::class, 'You need 1 credits and have 0');
        expect(app(CreditWallet::class)->balance()['balance_milli'])->toBe(0);
    });
    expect($ran)->toBeFalse();
});

it('charges once per confirmation and refunds automatically when the work fails', function () {
    $this->ctx->run($this->glow, function () {
        $wallet = app(CreditWallet::class);
        $wallet->grant('purchase', 5000, 'buy:1', 'Purchase');

        app(AiActions::class)->perform('rewrite', 'confirm-1', fn () => 'done');
        app(AiActions::class)->perform('rewrite', 'confirm-1', fn () => 'done'); // a double-click: same confirmation
        expect($wallet->balance()['balance_milli'])->toBe(4000);

        expect(fn () => app(AiActions::class)->perform('rewrite', 'confirm-2', fn () => throw new RuntimeException('AI down')))->toThrow(RuntimeException::class);
        expect($wallet->balance()['balance_milli'])->toBe(4000); // refunded
    });
});
