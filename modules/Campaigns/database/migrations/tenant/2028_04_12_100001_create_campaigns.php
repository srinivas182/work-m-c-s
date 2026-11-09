<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campaigns (Sprint 15.3a): email or SMS to customers who agreed to marketing.
        Schema::create('campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 120);
            $table->string('channel', 10);              // email | sms
            $table->string('segment', 20);              // all | recent | inactive | branch | referrers
            $table->ulid('branch_id')->nullable();
            $table->string('subject', 150)->nullable(); // email
            $table->text('body');
            $table->string('status', 12)->default('draft'); // draft | scheduled | sending | sent
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });
        // One row per recipient: what happened — no copy of the message.
        Schema::create('campaign_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('campaign_id')->index();
            $table->ulid('contact_id')->index();
            $table->char('token', 32)->unique();        // for tracked links, the open image and the opt-out
            $table->string('status', 10);               // sent | failed
            $table->string('error', 200)->nullable();
            $table->dateTime('opened_at')->nullable();
            $table->dateTime('clicked_at')->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_messages');
        Schema::dropIfExists('campaigns');
    }
};
