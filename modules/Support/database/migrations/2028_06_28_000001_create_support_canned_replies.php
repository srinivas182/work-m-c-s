<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Canned replies for WURK's support inbox (Sprint 16.3b). {name} and {business} are filled in when used. */
    public function up(): void
    {
        Schema::create('support_canned_replies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 80)->unique();
            $table->text('body');
            $table->unsignedInteger('uses')->default(0);
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_canned_replies');
    }
};
