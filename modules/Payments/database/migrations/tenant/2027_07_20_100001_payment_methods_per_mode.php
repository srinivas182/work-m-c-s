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
        // Test and live keys kept separately, so switching mode never means re-entering keys.
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->text('test_credentials')->nullable();
            $table->text('live_credentials')->nullable();
        });
        foreach (DB::table('payment_accounts')->get(['id', 'mode', 'credentials']) as $row) {
            DB::table('payment_accounts')->where('id', $row->id)->update([$row->mode === 'live' ? 'live_credentials' : 'test_credentials' => $row->credentials]);
        }
        Schema::table('payment_accounts', fn (Blueprint $table) => $table->dropColumn('credentials'));
    }

    public function down(): void
    {
        Schema::table('payment_accounts', fn (Blueprint $table) => $table->text('credentials')->nullable());
        foreach (DB::table('payment_accounts')->get(['id', 'mode', 'test_credentials', 'live_credentials']) as $row) {
            DB::table('payment_accounts')->where('id', $row->id)->update(['credentials' => $row->mode === 'live' ? $row->live_credentials : $row->test_credentials]);
        }
        Schema::table('payment_accounts', fn (Blueprint $table) => $table->dropColumn(['test_credentials', 'live_credentials']));
    }
};
