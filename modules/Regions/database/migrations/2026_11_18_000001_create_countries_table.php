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
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code', 2)->primary();
            $table->string('name', 80);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('vat_bp'); // basis points: 1500 = 15%
            $table->string('phone_prefix', 5);
            $table->string('timezone', 64);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        // South Africa at launch; neighbours prepared but switched off until WURK expands.
        // VAT rates for countries other than ZA must be confirmed before enabling them.
        $now = now();
        DB::table('countries')->insert([
            ['code' => 'ZA', 'name' => 'South Africa', 'currency' => 'ZAR', 'vat_bp' => 1500, 'phone_prefix' => '27', 'timezone' => 'Africa/Johannesburg', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BW', 'name' => 'Botswana', 'currency' => 'BWP', 'vat_bp' => 1400, 'phone_prefix' => '267', 'timezone' => 'Africa/Gaborone', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'NA', 'name' => 'Namibia', 'currency' => 'NAD', 'vat_bp' => 1500, 'phone_prefix' => '264', 'timezone' => 'Africa/Windhoek', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ZW', 'name' => 'Zimbabwe', 'currency' => 'USD', 'vat_bp' => 1550, 'phone_prefix' => '263', 'timezone' => 'Africa/Harare', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'KE', 'name' => 'Kenya', 'currency' => 'KES', 'vat_bp' => 1600, 'phone_prefix' => '254', 'timezone' => 'Africa/Nairobi', 'enabled' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};
