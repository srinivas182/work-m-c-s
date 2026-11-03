<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The chatbot's answers: quick buttons and keyword answers (rule-based; no AI).
        Schema::create('chat_answers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('kind', 10);                       // button | keyword
            $table->string('label', 30)->nullable();           // the button's text
            $table->string('keywords', 300)->nullable();       // comma-separated words that lead to this answer
            $table->string('answer', 600);
            $table->string('link_kind', 10)->default('none'); // none | page | call | whatsapp | map | email
            $table->string('link_target', 200)->nullable();    // a page's path
            $table->string('fact', 20)->nullable();            // hours | address | phone | whatsapp | services — follows the facts…
            $table->boolean('edited')->default(false);         // …until the owner rewrites it
            $table->unsignedSmallInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_answers');
    }
};
