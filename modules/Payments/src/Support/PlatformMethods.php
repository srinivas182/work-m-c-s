<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Illuminate\Support\Facades\DB;

/** Super admin: which payment methods businesses may offer (platform-wide; on unless switched off). */
final class PlatformMethods
{
    /** Card and instant-EFT providers (paid online), then manual EFT. */
    public const ONLINE = ['yoco', 'payfast', 'ozow', 'peach'];

    public const ALL = [...self::ONLINE, 'eft'];

    public const NAMES = ['yoco' => 'Yoco', 'payfast' => 'PayFast', 'ozow' => 'Ozow', 'peach' => 'Peach Payments', 'eft' => 'EFT / bank transfer'];

    public function available(string $provider): bool
    {
        return in_array($provider, self::ALL, true) && (bool) (DB::table('platform_payment_methods')->where('provider', $provider)->value('available') ?? true);
    }

    public function set(string $provider, bool $available): void
    {
        DB::table('platform_payment_methods')->updateOrInsert(['provider' => $provider], ['available' => $available, 'updated_at' => now(), 'created_at' => now()]);
    }
}
