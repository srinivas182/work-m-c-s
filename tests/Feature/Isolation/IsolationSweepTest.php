<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
| Sprint 18.2 — acceptance criterion 7: "no data leaks between businesses (verified by automated isolation tests)".
|
| Two businesses on the SAME shard (the hard case: only WURK's tenant scoping keeps them apart). Bravo gets a row in
| every table, every text column marked "BRAVOSECRET…". Alpha's owner then calls EVERY back-office route — every
| method, reads and writes — with Bravo's IDs in every parameter. No response may contain Bravo's data, nothing of
| Bravo's may change, and nothing may crash. New routes are covered automatically.
*/

/** Parameters that name a kind of thing rather than a row: try each real value, so the route is reached. */
const ISOLATION_VALUES = [
    'provider' => ['google', 'microsoft', 'zoom', 'yoco', 'payfast', 'stripe', 'paystack', 'bulksms', 'whatsapp'],
    'format' => ['csv', 'pdf'], 'mode' => ['test', 'live'], 'channel' => ['email', 'sms', 'whatsapp'],
    'number' => ['1', '1001'], 'step' => ['1', 'business', 'design'], 'type' => ['products', 'customers', 'bookings', 'pages'],
];

/** The tables a route parameter could point at: "contact" → contacts, "timeOff" → booking_time_off. */
function isolationTablesFor(string $param, array $tables): array
{
    $s = Str::snake($param);
    $plural = Str::plural($s);

    return array_values(array_filter($tables, fn ($t) => in_array($t, [$s, $plural], true) || str_ends_with($t, '_'.$s) || str_ends_with($t, '_'.$plural)));
}

/**
 * Calls every route of one kind with Bravo's IDs — "back-office": Alpha's back office, signed in as Alpha's owner;
 * "public": Alpha's own website address (bookings, orders, record links, campaign links, portal), signed out.
 * Returns what went wrong (LEAK, CRASH, CHANGED) and how many requests were made.
 *
 * @return array{0: list<string>, 1: int}
 */
function isolationSweep(TestCase $test, string $scope = 'back-office'): array
{
    $central = (string) config('database.default');
    $ids = [...$test->central->ids, ...$test->seeded->ids];
    $tables = array_keys($ids);
    $where = [...array_fill_keys(array_keys($test->central->ids), $central), ...array_fill_keys(array_keys($test->seeded->ids), $test->conn)];
    $snapshot = fn () => collect($where)->filter(fn ($c, $t) => Schema::connection($c)->hasColumn($t, 'tenant_id'))
        ->mapWithKeys(fn ($c, $t) => ["{$c}.{$t}" => md5((string) json_encode(DB::connection($c)->table($t)->where('tenant_id', $test->bravo->id)->get()))])->all();
    $before = $snapshot();

    $app = (string) config('wurk.domains.app');
    $site = 'alpha.'.config('wurk.domains.root');
    // Secrets in Bravo's rows (tokens, codes, references) are tried as well as its IDs.
    $secrets = collect($where)->filter(fn ($c, $t) => Schema::connection($c)->hasColumn($t, 'tenant_id'))->flatMap(fn ($c, $t) => collect(Schema::connection($c)->getColumnListing($t))
        ->filter(fn ($col) => preg_match('/(token|code|reference)$/', $col) === 1)
        ->map(fn ($col) => DB::connection($c)->table($t)->where('tenant_id', $test->bravo->id)->value($col)))->filter()->map(fn ($v) => (string) $v)->unique()->values()->all();
    $problems = [];
    $calls = 0;
    foreach (Route::getRoutes() as $route) {
        /** @var RoutingRoute $route */
        $public = $route->getDomain() === '{handle}.'.config('wurk.domains.root');
        $backOffice = str_starts_with($route->uri(), '{tenant}') && ($route->getDomain() === null || $route->getDomain() === $app);
        if ($scope === 'public' ? ! $public || $route->uri() === '{fallbackPlaceholder}' : ! $backOffice) {
            continue;
        }
        // Every parameter: each of Bravo's rows that it could mean, and a random ID when nothing matches.
        $choices = [[]];
        foreach (array_diff($route->parameterNames(), ['tenant']) as $param) {
            $candidates = [...array_map(fn ($t) => (string) $ids[$t], isolationTablesFor($param, $tables)), ...(ISOLATION_VALUES[$param] ?? []),
                ...(in_array($param, ['token', 'code', 'reference'], true) ? $secrets : [])];
            $candidates = $candidates ?: [strtolower((string) Str::ulid())];
            $next = [];
            foreach ($choices as $c) {
                foreach ($candidates as $id) {
                    $next[] = [...$c, $param => $id];
                }
            }
            $choices = array_slice($next, 0, 6);
        }
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            foreach ($choices as $params) {
                $path = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $m[1] === 'tenant' ? 'alpha' : rawurlencode($params[$m[1]] ?? ''), $route->uri());
                $path = rtrim((string) $path, '/');
                $calls++;
                if ($scope === 'public') {
                    Auth::logout();
                    $response = $test->call($method, "http://{$site}/{$path}", [], [], [], ['HTTP_ACCEPT' => 'text/html']);
                } else {
                    $response = $test->actingAs($test->owner)->call($method, "http://{$app}/{$path}", [], [], [], ['HTTP_ACCEPT' => 'text/html']);
                }
                // What we sent may be echoed back (an error page showing the address): that's not a leak.
                $sent = collect($params)->flatMap(fn ($v) => [(string) $v, rawurlencode((string) $v)])->all();
                $body = str_replace($sent, '', (string) $response->getContent());
                if (str_contains($body, SeedsEveryTable::MARKER)) {
                    $problems[] = "LEAK {$method} /{$path} ({$response->getStatusCode()})";
                }
                if ($response->getStatusCode() >= 500) {
                    $problems[] = "CRASH {$method} /{$path} ({$response->getStatusCode()}): ".Str::limit((string) ($response->exception?->getMessage() ?? ''), 160);
                }
            }
        }
    }

    $after = $snapshot();
    foreach ($before as $t => $hash) {
        if ($after[$t] !== $hash) {
            $problems[] = "CHANGED {$t}";
        }
    }

    return [$problems, $calls];
}

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
    $this->bravo = $this->createTenant('bravo', $shard, 'Bravo Bakery');
    foreach ([$this->alpha, $this->bravo] as $t) {
        app(Catalog::class)->subscribe($t->id, $package->id, 'monthly', allowTrial: true);
    }
    app(Entitlements::class)->flush();
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@alpha.co.za']);
    $this->member($this->owner, $this->alpha);
    app(TenantContext::class)->run($this->alpha, fn () => app(Team::class)->add($this->owner->id, 'Ayanda', 'owner'));
    $this->conn = app(TenantContext::class)->run($this->bravo, fn () => app(TenantContext::class)->connectionName());
    $this->seeded = (new SeedsEveryTable($this->conn, $this->bravo->id))->run();
    // Bravo's rows in the central database too: its invoices, support tickets, subscriptions, credit ledger…
    $this->central = (new SeedsEveryTable((string) config('database.default'), $this->bravo->id, tenantTablesOnly: true))->run();
});

it('fills every table of a business for the sweep — its shard and its rows in the central database', function () {
    expect($this->seeded->skipped)->toBe([])
        ->and(count($this->seeded->ids))->toBeGreaterThan(80)
        ->and($this->central->skipped)->toBe([])
        ->and(count($this->central->ids))->toBeGreaterThan(20);
});

it('never shows or changes one business’s data from another business’s back office — on any route', function () {
    [$problems, $calls] = isolationSweep($this);

    expect($calls)->toBeGreaterThan(250) // every back-office route, each with Bravo's IDs (294 when written)
        ->and($problems)->toBe([]);
});

it('never shows or changes one business’s data from another business’s website address — bookings, orders, record, payment and campaign links, the portal', function () {
    [$problems, $calls] = isolationSweep($this, 'public');

    expect($calls)->toBeGreaterThan(40)
        ->and($problems)->toBe([]);
});

it('would catch a route that forgets to check whose data it is (the sweep checking itself)', function () {
    $conn = $this->conn;
    // Two deliberately broken routes: one shows another business's customer, one changes it.
    Route::domain((string) config('wurk.domains.app'))->middleware('web')->group(function () use ($conn): void {
        Route::get('{tenant}/__leaky/{contact}', fn (string $tenant, string $contact) => (string) DB::connection($conn)->table('contacts')->where('id', $contact)->value('name'));
        Route::post('{tenant}/__careless/{contact}', function (string $tenant, string $contact) use ($conn) {
            DB::connection($conn)->table('contacts')->where('id', $contact)->update(['notes' => 'overwritten']);

            return 'ok';
        });
    });
    Route::getRoutes()->refreshNameLookups();
    [$problems] = isolationSweep($this);

    expect($problems)->toContain('LEAK GET /alpha/__leaky/'.$this->seeded->ids['contacts'].' (200)')
        ->toContain('CHANGED '.$conn.'.contacts');
});
