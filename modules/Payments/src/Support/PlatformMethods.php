<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Illuminate\Support\Facades\DB;

/** Super admin: which payment methods businesses may offer (platform-wide; on unless switched off). */
final class PlatformMethods
{
    /** Mobile money, direct (Sprint 22.2): a prompt on the customer's phone, money to the business's own till or wallet. */
    public const MOBILE = ['mpesa', 'mtn_momo', 'airtel_money'];

    /** Card, instant-EFT, QR and mobile money providers (paid online), then manual EFT. */
    public const ONLINE = ['yoco', 'payfast', 'ozow', 'peach', 'paystack', ...self::MOBILE];

    public const ALL = [...self::ONLINE, 'eft'];

    /** Methods WURK itself can take payments with (mobile money for WURK goes through Flutterwave, Sprint 22.4). */
    public const WURK = ['yoco', 'payfast', 'ozow', 'peach', 'paystack', 'eft'];

    public const NAMES = ['yoco' => 'Yoco', 'payfast' => 'PayFast', 'ozow' => 'Ozow', 'peach' => 'Peach Payments', 'paystack' => 'Paystack',
        'mpesa' => 'M-Pesa', 'mtn_momo' => 'MTN MoMo', 'airtel_money' => 'Airtel Money', 'eft' => 'EFT / bank transfer'];

    /** Where each mobile money provider works; the others work wherever WURK does. */
    public const COUNTRIES = ['mpesa' => ['KE'], 'mtn_momo' => ['GH', 'UG', 'ZM', 'CM', 'CI', 'RW'], 'airtel_money' => ['KE', 'UG', 'TZ', 'ZM', 'MW', 'RW']];

    /** Whether a business in this country can offer the method. */
    public static function servesCountry(string $provider, string $country): bool
    {
        return ! isset(self::COUNTRIES[$provider]) || in_array(strtoupper($country), self::COUNTRIES[$provider], true);
    }

    /** @return list<string> the methods a business in this country can offer */
    public static function forCountry(string $country): array
    {
        return array_values(array_filter(self::ALL, fn (string $p) => self::servesCountry($p, $country)));
    }

    public function available(string $provider): bool
    {
        return in_array($provider, self::ALL, true) && (bool) (DB::table('platform_payment_methods')->where('provider', $provider)->value('available') ?? true);
    }

    public function set(string $provider, bool $available): void
    {
        DB::table('platform_payment_methods')->updateOrInsert(['provider' => $provider], ['available' => $available, 'updated_at' => now(), 'created_at' => now()]);
    }
}
