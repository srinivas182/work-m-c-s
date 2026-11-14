<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Tests\Support\SeedsEveryTable;
use Tests\TestCase;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Support\ModuleCatalogue;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

/*
| Sprint 18.3a — no N+1 queries on the busiest screens. Each screen is loaded with a few rows, then with many more:
| the number of database queries may not grow with the rows (a query per row is what makes pages slow at scale).
*/

beforeEach(function () {
    Mail::fake();
    [$shard] = $this->createShards(1);
    $package = app(PackageCatalog::class)->create([
        'key' => 'everything', 'name' => 'Everything', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 99900, 'yearly_price_cents' => 999000, 'trial_days' => 14, 'storage_mb' => 4096, 'staff_limit' => 50,
        'multi_branch' => true, 'max_branches' => 10, 'custom_domain' => true, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => app(ModuleCatalogue::class)->keys(),
    ], null);
    $this->alpha = $this->createTenant('alpha', $shard, 'Alpha Salon');
    app(Catalog::class)->subscribe($this->alpha->id, $package->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@alpha.co.za']);
    $this->member($this->owner, $this->alpha);
    app(TenantContext::class)->run($this->alpha, fn () => app(Team::class)->add($this->owner->id, 'Ayanda', 'owner'));
    $conn = app(TenantContext::class)->run($this->alpha, fn () => app(TenantContext::class)->connectionName());
    $this->rows = (new SeedsEveryTable($conn, $this->alpha->id))->run();
});

/** Queries a page makes. */
function queriesFor(TestCase $test, string $path): int
{
    $n = 0;
    $listener = function (QueryExecuted $q) use (&$n): void {
        $n++;
    };
    Event::listen(QueryExecuted::class, $listener);
    $response = $test->actingAs($test->owner)->get('http://'.config('wurk.domains.app').$path);
    Event::forget(QueryExecuted::class);
    expect($response->getStatusCode())->toBe(200, "{$path} answered ".$response->getStatusCode());

    return $n;
}

it('doesn’t make more queries when a busy screen has more rows', function (string $path, string $table) {
    queriesFor($this, $path);                                     // warm (first-request work: settings, caches)
    $few = queriesFor($this, $path);
    $this->rows->copies($table, 25);
    $many = queriesFor($this, $path);

    expect($many - $few)->toBeLessThanOrEqual(2, "{$path}: {$few} queries with 1 row, {$many} with 26 — a query per row?");
})->with([
    'customers' => ['/alpha/contacts', 'contacts'],
    'leads' => ['/alpha/leads', 'leads'],
    'today’s bookings' => ['/alpha/bookings', 'bookings'],
    'products' => ['/alpha/shop/products', 'shop_products'],
    'orders' => ['/alpha/shop/orders', 'shop_orders'],
    'campaigns' => ['/alpha/campaigns', 'campaigns'],
    'pages' => ['/alpha/website/pages', 'pages'],
    'enquiries' => ['/alpha/website/enquiries', 'enquiries'],
    'team' => ['/alpha/settings/team', 'members'],
]);
