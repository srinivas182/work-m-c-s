<?php

declare(strict_types=1);

namespace Wurk\Identity\Support;

use Illuminate\Http\Request;

/** Which surface a request is for: WURK staff (admin), businesses (app), or a business's customers on its own site (portal). */
final class Surface
{
    public const ADMIN = 'admin';

    public const APP = 'app';

    /** A business's customer portal on its own address (Sprint 13.6). */
    public const PORTAL = 'portal';

    public static function of(Request $request): string
    {
        $host = strtolower($request->getHost());

        return match (true) {
            $host === strtolower((string) config('wurk.domains.admin')) => self::ADMIN,
            $host === strtolower((string) config('wurk.domains.app')) => self::APP,
            // A business's WURK address ({handle}.{root}); sessions are host-only (SESSION_DOMAIN=null).
            // TODO(13.6): businesses on their own custom domain.
            str_ends_with($host, '.'.strtolower((string) config('wurk.domains.root'))) => self::PORTAL,
            default => self::APP,
        };
    }

    /** @return array{idle: int, absolute: int, remember: bool} */
    public static function sessionPolicy(string $surface): array
    {
        /** @var array{idle: int, absolute: int, remember: bool} */
        return config("wurk.identity.sessions.{$surface}");
    }
}
