<?php

declare(strict_types=1);

namespace Wurk\Identity\Mfa;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Wurk\Identity\Models\IdentityMfa;

/** Ten single-use recovery codes, stored hashed. */
final class RecoveryCodes
{
    /** @return array{plain: list<string>, hashed: list<string>} */
    public static function generate(int $count = 10): array
    {
        $plain = [];
        for ($i = 0; $i < $count; $i++) {
            $plain[] = Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5));
        }

        return ['plain' => $plain, 'hashed' => array_map(fn (string $c) => Hash::make($c), $plain)];
    }

    /** Consumes a recovery code if it matches. */
    public static function use(IdentityMfa $mfa, string $code): bool
    {
        $code = Str::lower(trim($code));
        $codes = $mfa->recovery_codes ?? [];

        foreach ($codes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$i]);
                $mfa->forceFill(['recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }
}
