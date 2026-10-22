<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

/** South African banking details: universal branch codes and ID number checks. */
final class SaBanking
{
    /** Banks with a universal branch code (prefilled; any 6-digit code is accepted). */
    public const BANKS = [
        'ABSA' => '632005',
        'African Bank' => '430000',
        'Capitec' => '470010',
        'Discovery Bank' => '679000',
        'FNB' => '250655',
        'Investec' => '580105',
        'Nedbank' => '198765',
        'Standard Bank' => '051001',
        'TymeBank' => '678910',
    ];

    public const ACCOUNT_TYPES = ['cheque', 'savings', 'transmission'];

    /** 13 digits, a real date of birth, and a valid Luhn check digit. */
    public static function validIdNumber(string $id): bool
    {
        if (preg_match('/^\d{13}$/', $id) !== 1) {
            return false;
        }
        [$yy, $mm, $dd] = [(int) substr($id, 0, 2), (int) substr($id, 2, 2), (int) substr($id, 4, 2)];
        if (! checkdate($mm, $dd, 2000 + $yy) && ! checkdate($mm, $dd, 1900 + $yy)) {
            return false;
        }

        $sum = 0;
        foreach (str_split(strrev($id)) as $i => $digit) {
            $d = (int) $digit;
            if ($i % 2 === 1) {
                $d *= 2;
                $d = $d > 9 ? $d - 9 : $d;
            }
            $sum += $d;
        }

        return $sum % 10 === 0;
    }

    public static function mask(string $account): string
    {
        return '••••'.substr($account, -4);
    }
}
