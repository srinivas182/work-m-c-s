<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 60)->unique();
            $table->string('name', 80);
            $table->string('description', 500)->default('');
            $table->string('type', 20); // self_managed | managed
            $table->string('visibility', 20)->default('public'); // public | private
            $table->unsignedSmallInteger('display_order')->default(100);
            $table->string('status', 20)->default('active'); // active | archived
            $table->ulid('current_version_id')->nullable();
            $table->timestamps();
        });

        // Immutable: every commercial change creates a new version (grandfathering).
        Schema::create('package_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('package_id')->constrained('packages')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->char('currency', 3)->default('ZAR');
            $table->unsignedInteger('monthly_price_cents');
            $table->unsignedInteger('yearly_price_cents');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedInteger('storage_mb');
            $table->unsignedSmallInteger('staff_limit');
            $table->boolean('multi_branch')->default(false);
            $table->unsignedSmallInteger('max_branches')->default(1);
            $table->boolean('custom_domain')->default(false);
            $table->unsignedInteger('monthly_credits')->default(0);
            $table->unsignedTinyInteger('referral_max_levels')->default(7);
            $table->unsignedSmallInteger('referral_max_commission_bp')->default(3000); // basis points: 3000 = 30%
            $table->unsignedInteger('managed_fee_cents')->nullable();
            $table->json('modules');
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['package_id', 'version']);
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUlid('package_id')->constrained('packages');
            $table->foreignUlid('package_version_id')->constrained('package_versions');
            $table->string('status', 20)->default('active'); // trial | active (billing states arrive in Sprint 3)
            $table->timestamp('started_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('entitlement_overrides', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('module_key', 60);
            $table->boolean('granted');
            $table->string('reason', 500);
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->primary(['tenant_id', 'module_key']);
        });
    }

    public function down(): void
    {
        foreach (['entitlement_overrides', 'tenant_subscriptions', 'package_versions', 'packages'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
