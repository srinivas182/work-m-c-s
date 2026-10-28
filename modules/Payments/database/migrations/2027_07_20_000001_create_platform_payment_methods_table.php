<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Super admin: which methods businesses may offer (platform-wide).
        Schema::create('platform_payment_methods', function (Blueprint $table) {
            $table->string('provider', 10)->primary(); // payfast | yoco | ozow | peach | eft
            $table->boolean('available')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payment_methods');
    }
};
