<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files visitors attach to forms: encrypted on a private disk; released only once scanned clean.
        Schema::create('enquiry_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('enquiry_id')->index();
            $table->string('name', 160);           // the visitor's file name (shown, never used as a path)
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->string('path', 200)->nullable(); // null once removed (infected, or archived and expired)
            $table->string('status', 12)->default('pending'); // pending | clean | infected | removed
            $table->dateTime('scanned_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_files');
    }
};
