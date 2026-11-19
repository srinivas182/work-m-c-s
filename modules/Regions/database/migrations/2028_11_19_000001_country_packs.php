<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Country packs (Sprint 25.1). A country carries what a business there starts with: its currency and how money is
 * written, its tax name and rate (with dated history), its phone format, week start, languages, payment and
 * messaging providers, and which legal templates apply.
 *
 * Rates for every country except South Africa (live since launch) are seeded unconfirmed, from PwC Tax Summaries
 * (Nov 2026). HQ confirms each one in Admin → Countries; a country can't open for sign-up until its rate is confirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->string('currency_symbol', 8)->default('R')->after('currency');
            $table->unsignedTinyInteger('currency_decimals')->default(2)->after('currency_symbol');
            $table->string('decimal_separator', 1)->default('.')->after('currency_decimals');
            $table->string('thousands_separator', 3)->default(' ')->after('decimal_separator');
            $table->boolean('symbol_after')->default(false)->after('thousands_separator'); // 5 000 CFA, not CFA5 000
            $table->string('tax_name', 20)->default('VAT')->after('vat_bp');
            $table->boolean('prices_include_tax')->default(true)->after('tax_name');
            $table->unsignedTinyInteger('phone_digits')->default(9)->after('phone_prefix'); // national number, without the leading 0
            $table->unsignedTinyInteger('week_starts_on')->default(1)->after('timezone'); // ISO: 1 = Monday, 7 = Sunday
            $table->json('languages')->nullable()->after('week_starts_on');
            $table->json('payment_providers')->nullable()->after('languages');
            $table->string('sms_provider', 30)->nullable()->after('payment_providers');
            $table->string('ussd_provider', 30)->nullable()->after('sms_provider');
            $table->string('returns_template', 30)->default('generic')->after('ussd_provider');
        });

        Schema::create('country_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2)->index();
            $table->unsignedSmallInteger('rate_bp'); // 1500 = 15%
            $table->json('levies')->nullable(); // [{"name": "NHIL", "bp": 250}, …] — charged on top, shown with the tax
            $table->date('starts_on');
            $table->string('note', 200)->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('confirmed_by', 26)->nullable();
            $table->string('created_by', 26)->nullable();
            $table->datetimes();
            $table->unique(['country_code', 'starts_on']);
        });

        $packs = [
            // code => [name, currency, symbol, decimals, decimal sep, thousands sep, symbol after, rate bp, levies, phone prefix, digits, timezone, week start, languages, providers, sms, ussd, returns, confirmed]
            'ZA' => ['South Africa', 'ZAR', 'R', 2, '.', ' ', false, 1500, null, '27', 9, 'Africa/Johannesburg', 1, ['en', 'zu', 'xh', 'af', 'st'], ['payfast', 'yoco', 'ozow', 'peach', 'paystack', 'eft'], 'smsportal', 'africastalking', 'za-cpa', true],
            'NA' => ['Namibia', 'NAD', 'N$', 2, '.', ' ', false, 1500, null, '264', 9, 'Africa/Windhoek', 1, ['en', 'af'], ['paystack', 'eft'], 'africastalking', null, 'generic', false],
            'BW' => ['Botswana', 'BWP', 'P', 2, '.', ',', false, 1400, null, '267', 8, 'Africa/Gaborone', 1, ['en'], ['paystack', 'eft'], 'africastalking', null, 'generic', false],
            'SZ' => ['Eswatini', 'SZL', 'E', 2, '.', ',', false, 1500, null, '268', 8, 'Africa/Mbabane', 1, ['en'], ['mtn_momo', 'eft'], 'africastalking', null, 'generic', false],
            'KE' => ['Kenya', 'KES', 'KSh', 2, '.', ',', false, 1600, null, '254', 9, 'Africa/Nairobi', 1, ['en', 'sw'], ['mpesa', 'airtel_money', 'paystack', 'flutterwave'], 'africastalking', 'africastalking', 'generic', false],
            'NG' => ['Nigeria', 'NGN', '₦', 2, '.', ',', false, 750, null, '234', 10, 'Africa/Lagos', 1, ['en', 'ha', 'yo'], ['paystack', 'flutterwave'], 'africastalking', 'africastalking', 'generic', false],
            'GH' => ['Ghana', 'GHS', 'GH₵', 2, '.', ',', false, 1500, [['name' => 'NHIL', 'bp' => 250], ['name' => 'GETFund levy', 'bp' => 250]], '233', 9, 'Africa/Accra', 1, ['en', 'ha'], ['mtn_momo', 'paystack', 'flutterwave'], 'africastalking', 'africastalking', 'generic', false],
            'CI' => ['Côte d’Ivoire', 'XOF', 'CFA', 0, ',', ' ', true, 1800, null, '225', 10, 'Africa/Abidjan', 1, ['fr'], ['mtn_momo', 'flutterwave'], 'africastalking', null, 'generic', false],
        ];
        $now = now();
        foreach ($packs as $code => [$name, $currency, $symbol, $decimals, $dec, $thou, $after, $rate, $levies, $prefix, $digits, $tz, $week, $langs, $providers, $sms, $ussd, $returns, $confirmed]) {
            $values = ['name' => $name, 'currency' => $currency, 'currency_symbol' => $symbol, 'currency_decimals' => $decimals, 'decimal_separator' => $dec,
                'thousands_separator' => $thou, 'symbol_after' => $after, 'vat_bp' => $rate, 'phone_prefix' => $prefix, 'phone_digits' => $digits, 'timezone' => $tz,
                'week_starts_on' => $week, 'languages' => json_encode($langs), 'payment_providers' => json_encode($providers), 'sms_provider' => $sms,
                'ussd_provider' => $ussd, 'returns_template' => $returns, 'updated_at' => $now];
            if (DB::table('countries')->where('code', $code)->exists()) {
                DB::table('countries')->where('code', $code)->update($values); // keeps `enabled` as HQ set it
            } else {
                DB::table('countries')->insert($values + ['code' => $code, 'enabled' => false, 'created_at' => $now]);
            }
            DB::table('country_tax_rates')->insert(['country_code' => $code, 'rate_bp' => $rate, 'levies' => $levies === null ? null : json_encode($levies),
                'starts_on' => '2026-01-01', 'note' => $confirmed ? 'In use since launch' : 'From PwC Tax Summaries, Nov 2026 — to confirm',
                'confirmed_at' => $confirmed ? $now : null, 'created_at' => $now, 'updated_at' => $now]);
        }
        // Zimbabwe stays as it was (off), with its rate recorded unconfirmed.
        $zw = DB::table('countries')->where('code', 'ZW')->first();
        if ($zw !== null) {
            DB::table('countries')->where('code', 'ZW')->update(['currency_symbol' => 'US$', 'thousands_separator' => ',', 'languages' => json_encode(['en']), 'payment_providers' => json_encode(['eft'])]);
            DB::table('country_tax_rates')->insert(['country_code' => 'ZW', 'rate_bp' => (int) $zw->vat_bp, 'starts_on' => '2026-01-01', 'note' => 'To confirm', 'created_at' => $now, 'updated_at' => $now]);
        }
        // A country that's already open and isn't confirmed can't stay open on an unchecked rate.
        DB::table('countries')->where('enabled', true)->whereNotIn('code', ['ZA'])->update(['enabled' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('country_tax_rates');
        Schema::table('countries', function (Blueprint $table) {
            $table->dropColumn(['currency_symbol', 'currency_decimals', 'decimal_separator', 'thousands_separator', 'symbol_after', 'tax_name', 'prices_include_tax',
                'phone_digits', 'week_starts_on', 'languages', 'payment_providers', 'sms_provider', 'ussd_provider', 'returns_template']);
        });
    }
};
