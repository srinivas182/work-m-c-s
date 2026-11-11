<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What things cost WURK (Sprint 15.5a), set in Admin → Profitability — never guessed:
        //  provider: per 1,000 messages, per channel and country ('' = every country)
        //  running:  per business per month (hosting, storage, support), per package ('' = every package) — decision A
        Schema::create('cost_settings', function (Blueprint $table) {
            $table->string('kind', 10);
            $table->string('channel', 20)->default('');
            $table->string('country', 2)->default('');
            $table->string('package_id', 26)->default('');
            $table->unsignedInteger('cents');
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->primary(['kind', 'channel', 'country', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_settings');
    }
};
