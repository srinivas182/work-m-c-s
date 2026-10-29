<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $main = '(select b.id from branches b where b.tenant_id = %s.tenant_id and b.is_default = 1 limit 1)';
        // Orders are fulfilled by a branch; existing ones by their business's main branch.
        Schema::table('shop_orders', fn (Blueprint $t) => $t->ulid('branch_id')->nullable()->index());
        DB::table('shop_orders')->update(['branch_id' => DB::raw(sprintf($main, 'shop_orders'))]);
        // Delivery orders ship from this branch (none = the main branch).
        Schema::table('shop_settings', fn (Blueprint $t) => $t->ulid('ships_from_branch_id')->nullable());

        // Tracked stock per branch (shop_variants.stock stays as the cached total). Existing stock → the main branch.
        Schema::create('shop_stock', function (Blueprint $table) {
            $table->ulid('variant_id');
            $table->ulid('branch_id');
            $table->ulid('tenant_id')->index();
            $table->integer('quantity');
            $table->primary(['variant_id', 'branch_id']);
        });
        DB::statement('insert into shop_stock (variant_id, branch_id, tenant_id, quantity) select v.id, '.sprintf($main, 'v').', v.tenant_id, v.stock from shop_variants v where v.stock is not null');

        // An optional price per variant per branch.
        Schema::create('shop_variant_prices', function (Blueprint $table) {
            $table->ulid('variant_id');
            $table->ulid('branch_id');
            $table->ulid('tenant_id')->index();
            $table->unsignedInteger('price_cents');
            $table->primary(['variant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_variant_prices');
        Schema::dropIfExists('shop_stock');
        Schema::table('shop_settings', fn (Blueprint $t) => $t->dropColumn('ships_from_branch_id'));
        Schema::table('shop_orders', fn (Blueprint $t) => $t->dropColumn('branch_id'));
    }
};
