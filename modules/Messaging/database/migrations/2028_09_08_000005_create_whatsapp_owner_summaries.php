<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 21.6: the owner's evening WhatsApp summary, and the requests in it the owner can answer by replying with a
     * number. Central, because replies come to WURK's own number and are matched by the owner's verified mobile.
     */
    public function up(): void
    {
        Schema::create('whatsapp_owner_summaries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('identity_id');
            $table->boolean('enabled')->default(true);
            $table->string('send_at', 5)->default('19:00');  // the business's local time
            $table->date('last_sent_on')->nullable();         // the business's local date
            $table->datetimes();
            $table->unique(['tenant_id', 'identity_id']);
        });
        Schema::create('whatsapp_owner_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('identity_id');
            $table->string('phone', 20)->index();              // digits, as WhatsApp sends them
            $table->unsignedTinyInteger('number');
            $table->string('source', 20);                      // the module's digest key, e.g. "sale"
            $table->string('ref', 40);                         // the request's id in that module
            $table->string('label', 200);
            $table->dateTime('expires_at');
            $table->dateTime('done_at')->nullable();
            $table->string('outcome', 200)->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_owner_actions');
        Schema::dropIfExists('whatsapp_owner_summaries');
    }
};
