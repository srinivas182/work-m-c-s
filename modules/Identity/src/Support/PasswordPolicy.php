<?php

declare(strict_types=1);

namespace Wurk\Identity\Support;

use Wurk\Identity\Rules\NotCommonPassword;

final class PasswordPolicy
{
    /** @return list<mixed> */
    public static function rules(): array
    {
        return ['required', 'string', 'min:'.(int) config('wurk.identity.password.min_length', 10), 'max:128', new NotCommonPassword];
    }
}
