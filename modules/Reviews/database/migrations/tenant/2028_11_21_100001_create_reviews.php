<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Reviews (Sprint 25.3): requests after a visit or order, the reviews customers leave, the numbers that said STOP,
 * and the business's review settings. A hidden review keeps its stated reason and still counts in the average.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('visit_key', 80); // e.g. booking:01J…  — one request per visit, ever
            $table->string('kind', 20);
            $table->string('title', 120);
            $table->string('customer_name', 120);
            $table->string('phone', 20)->nullable();
            $table->string('channel', 20); // whatsapp | sms | none
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('answered_at')->nullable();
            $table->datetimes();
            $table->unique(['tenant_id', 'visit_key']);
            $table->index(['tenant_id', 'phone', 'sent_at']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('request_id')->nullable()->unique();
            $table->string('name', 60); // first name and initial, as shown
            $table->string('title', 120); // what it was for
            $table->unsignedTinyInteger('rating'); // 1–5
            $table->text('comment')->nullable(); // shown on the site
            $table->text('private_note')->nullable(); // for the owner only
            $table->text('reply')->nullable();
            $table->dateTime('replied_at')->nullable();
            $table->string('hidden_reason', 30)->nullable(); // abusive | personal_data | not_a_customer
            $table->ulid('hidden_by')->nullable();
            $table->dateTime('hidden_at')->nullable();
            $table->datetimes();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('review_stops', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->string('phone', 20);
            $table->dateTime('stopped_at');
            $table->primary(['tenant_id', 'phone']);
        });

        Schema::create('review_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->boolean('requests_on')->default(true);
            $table->string('google_url', 300)->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_settings');
        Schema::dropIfExists('review_stops');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('review_requests');
    }
};
