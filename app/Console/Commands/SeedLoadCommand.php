<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Support\ModuleCatalogue;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Sprint 18.3b — realistic data for load tests: N businesses spread over the shards, each with an owner (who can sign
 * in with a password), customers, a bookable service with opening hours and bookings, and products. Writes
 * storage/app/load-test.json for the k6 scripts (load/k6). For staging and local machines only — refused in
 * production.
 */
final class SeedLoadCommand extends Command
{
    public const PASSWORD = 'load test password 2026';

    protected $signature = 'wurk:seed-load
        {--businesses=20 : How many businesses}
        {--customers=200 : Customers per business}
        {--bookings=60 : Bookings per business, spread over the next 14 days}
        {--products=20 : Products per business}
        {--prefix=load : Handle prefix (load-1, load-2, …)}';

    protected $description = 'Create load-test businesses with customers, bookings and products (not in production)';

    public function handle(TenantDirectory $directory, TenantContext $context, Catalog $catalog): int
    {
        if (app()->isProduction()) {
            $this->components->error('Load-test data is never created in production.');

            return self::FAILURE;
        }
        $package = $this->package();
        $n = max(1, (int) $this->option('businesses'));
        $prefix = Str::slug(is_string($p = $this->option('prefix')) ? $p : 'load');
        $out = ['password' => self::PASSWORD, 'businesses' => []];
        $bar = $this->output->createProgressBar($n);

        for ($i = 1; $i <= $n; $i++) {
            $handle = "{$prefix}-{$i}";
            $tenant = $directory->findByHandle($handle);
            if ($tenant === null) {
                Artisan::call('tenants:create', ['name' => 'Load Test '.$i, '--handle' => $handle]);
                $tenant = $directory->findByHandle($handle) ?? throw new \RuntimeException("Couldn't create {$handle}.");
                $catalog->subscribe($tenant->tenantId(), $package->id, 'monthly', allowTrial: true);
                Artisan::call('identities:create', ['email' => "owner@{$handle}.test", '--first' => 'Owner', '--last' => (string) $i,
                    '--password' => self::PASSWORD, '--tenant' => [$handle], '--role' => 'owner']);
            }
            $service = $context->run($tenant, fn (): string => $this->fill($context, $tenant->tenantId()));
            $out['businesses'][] = ['handle' => $handle, 'owner' => "owner@{$handle}.test", 'service' => $service, 'shard' => $tenant->shardId()];
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        file_put_contents(storage_path('app/load-test.json'), json_encode($out, JSON_PRETTY_PRINT));
        $this->components->info("{$n} businesses ready; details in storage/app/load-test.json.");

        return self::SUCCESS;
    }

    private function package(): Package
    {
        return Package::query()->where('key', 'load-test')->first() ?? app(PackageCatalog::class)->create([
            'key' => 'load-test', 'name' => 'Load test', 'type' => 'self_managed', 'visibility' => 'public', // only public packages can be chosen; never in production 'display_order' => 99,
            'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 30, 'storage_mb' => 1024, 'staff_limit' => 10,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => app(ModuleCatalogue::class)->keys(),
        ], null);
    }

    /** One business's data (only once: a second run leaves it as it is). Returns its bookable service. */
    private function fill(TenantContext $context, string $tenantId): string
    {
        $db = DB::connection($context->connectionName());
        $existing = $db->table('booking_services')->where('tenant_id', $tenantId)->value('id');
        if ($existing !== null) {
            return (string) $existing;
        }
        $id = fn (): string => strtolower((string) Str::ulid());
        $now = now();
        $member = (string) $db->table('members')->where('tenant_id', $tenantId)->value('id');

        foreach (array_chunk(range(1, (int) $this->option('customers')), 500) as $chunk) {
            $db->table('contacts')->insert(array_map(fn (int $c) => ['id' => $id(), 'tenant_id' => $tenantId, 'name' => "Customer {$c}", 'email' => "customer{$c}@example.test",
                'phone' => '+2782'.str_pad((string) $c, 7, '0', STR_PAD_LEFT), 'marketing_consent_at' => $c % 3 === 0 ? $now : null,
                'last_seen_at' => $now->copy()->subDays($c % 90), 'created_at' => $now, 'updated_at' => $now], $chunk));
        }

        $service = $id();
        $db->table('booking_services')->insert(['id' => $service, 'tenant_id' => $tenantId, 'name' => 'Cut and style', 'duration_minutes' => 30, 'active' => true,
            'created_at' => $now, 'updated_at' => $now]);
        $db->table('booking_service_member')->insert(['service_id' => $service, 'member_id' => $member, 'tenant_id' => $tenantId]);
        $db->table('booking_hours')->insert(array_map(fn (int $d) => ['id' => $id(), 'tenant_id' => $tenantId, 'member_id' => $member, 'weekday' => $d,
            'starts' => '08:00:00', 'ends' => '18:00:00'], range(0, 6)));
        $bookings = [];
        for ($b = 0; $b < (int) $this->option('bookings'); $b++) {
            $start = $now->copy()->startOfDay()->addDays($b % 14)->setTime(8 + intdiv($b, 14) % 10, ($b % 2) * 30);
            $bookings[] = ['id' => $id(), 'tenant_id' => $tenantId, 'service_id' => $service, 'member_id' => $member, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30),
                'status' => 'confirmed', 'customer_name' => "Customer {$b}", 'customer_email' => "customer{$b}@example.test", 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($bookings, 500) as $chunk) {
            $db->table('bookings')->insert($chunk);
        }

        for ($p = 1; $p <= (int) $this->option('products'); $p++) {
            $product = $id();
            $db->table('shop_products')->insert(['id' => $product, 'tenant_id' => $tenantId, 'name' => "Product {$p}", 'slug' => "product-{$p}", 'status' => 'published',
                'created_at' => $now, 'updated_at' => $now]);
            $db->table('shop_variants')->insert(['id' => $id(), 'tenant_id' => $tenantId, 'product_id' => $product, 'price_cents' => 10000 + $p * 500,
                'created_at' => $now, 'updated_at' => $now]);
        }

        return $service;
    }
}
