<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each enquiry belongs to a contact (and, if the form opens leads, a lead); marketing consent only when ticked.
        Schema::table('enquiries', function (Blueprint $table) {
            $table->ulid('contact_id')->nullable()->index();
            $table->ulid('lead_id')->nullable();
            $table->dateTime('marketing_consent_at')->nullable();
            $table->json('answers')->nullable(); // the business's own questions and the visitor's answers
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', fn (Blueprint $table) => $table->dropColumn(['contact_id', 'lead_id', 'marketing_consent_at', 'answers']));
    }
};
