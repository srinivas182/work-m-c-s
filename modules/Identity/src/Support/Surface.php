<?php

declare(strict_types=1);

namespace Wurk\Identity\Support;

use Illuminate\Http\Request;

/** Which back office a request is for: WURK staff (admin) or businesses (app). */
final class Surface
{
    public const ADMIN = 'admin';

    public const APP = 'app';

    public static function of(Request $request): string
    {
        return strtolower($request->getHost()) === strtolower((string) config('wurk.domains.admin')) ? self::ADMIN : self::APP;
    }

    /** @return array{idle: int, absolute: int, remember: bool} */
    public static function sessionPolicy(string $surface): array
    {
        /** @var array{idle: int, absolute: int, remember: bool} */
        return config("wurk.identity.sessions.{$surface}");
    }
}
