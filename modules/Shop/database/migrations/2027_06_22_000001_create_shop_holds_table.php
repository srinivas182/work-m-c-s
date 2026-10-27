<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central list of unpaid orders holding stock, so the expiry sweep needn't visit every shard.
        Schema::create('shop_holds', function (Blueprint $table) {
            $table->ulid('order_id')->primary();
            $table->ulid('tenant_id')->index();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_holds');
    }
};
